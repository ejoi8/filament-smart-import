<?php

namespace SmartImport\Services;

use finfo;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use SmartImport\Data\ImportActorIdentity;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Models\ImportSession;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final class FileIntakeService
{
    private const FORMATS = [
        'csv' => [
            'reader' => IOFactory::READER_CSV,
            'mime_types' => ['application/csv', 'application/vnd.ms-excel', 'text/csv', 'text/plain'],
        ],
        'xlsx' => [
            'reader' => IOFactory::READER_XLSX,
            'mime_types' => [
                'application/octet-stream',
                'application/zip',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ],
        'xls' => [
            'reader' => IOFactory::READER_XLS,
            'mime_types' => [
                'application/cdfv2',
                'application/octet-stream',
                'application/vnd.ms-excel',
                'application/x-cfb',
                'application/x-ole-storage',
            ],
        ],
    ];

    public function __construct(private ImportCoordinator $coordinator) {}

    /**
     * Store and register a browser upload as an unassigned intake record.
     * Tenant IDs, when supplied, must come from trusted host context.
     */
    public function upload(
        UploadedFile $file,
        Authenticatable $actor,
        ?string $tenantId = null,
    ): ImportSession {
        $identity = ImportActorIdentity::from($actor);
        $this->coordinator->authorizeIntakeCreation($actor, $tenantId);
        $this->consumeUploadQuota($identity);

        if (! $file->isValid()) {
            throw new InvalidArgumentException('The uploaded file could not be received successfully.');
        }

        $sourcePath = $file->getPathname();

        if (! is_file($sourcePath) || is_link($sourcePath) || ! is_readable($sourcePath)) {
            throw new InvalidArgumentException('The uploaded file is not a readable regular file.');
        }

        $size = filesize($sourcePath);
        $maximumSize = (int) config('smart-import.uploads.max_file_size_bytes', 10 * 1024 * 1024);

        if ($maximumSize < 1) {
            throw new RuntimeException('The Smart Import maximum upload size must be greater than zero.');
        }

        if (! is_int($size) || $size < 1) {
            throw new InvalidArgumentException('Empty or unreadable files cannot be imported.');
        }

        if ($size > $maximumSize) {
            throw new InvalidArgumentException('The uploaded file exceeds the configured size limit.');
        }

        $filename = $this->safeFilename($file->getClientOriginalName());
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! isset(self::FORMATS[$extension])) {
            throw new InvalidArgumentException('Only CSV, XLSX, and XLS files are accepted.');
        }

        if (! mb_check_encoding($filename, 'UTF-8')) {
            throw new InvalidArgumentException('The uploaded filename is invalid.');
        }

        $diskName = (string) config('smart-import.storage.disk', 'local');
        $this->assertPrivateDisk($diskName);
        $directory = $this->uploadDirectory();
        $sourceHash = hash_file('sha256', $sourcePath);

        if (! is_string($sourceHash)) {
            throw new InvalidArgumentException('The uploaded file could not be checksummed.');
        }

        $this->assertSupportedContent($sourcePath, $extension);
        clearstatcache(true, $sourcePath);

        $checkedSize = filesize($sourcePath);
        $checkedHash = hash_file('sha256', $sourcePath);

        if ($checkedSize !== $size || ! is_string($checkedHash) || ! hash_equals($sourceHash, $checkedHash)) {
            throw new InvalidArgumentException('The uploaded file changed while it was being checked.');
        }

        $pathToken = (string) Str::uuid();
        $temporaryPath = $directory.'/'.$pathToken.'.'.$extension.'.part';
        $storagePath = $directory.'/'.$pathToken.'.'.$extension;

        $disk = Storage::disk($diskName);

        try {
            $storedTemporaryPath = $disk->putFileAs(
                $directory,
                $file,
                basename($temporaryPath),
                ['visibility' => 'private'],
            );

            if ($storedTemporaryPath === false || ! $disk->move($temporaryPath, $storagePath)) {
                throw new RuntimeException('The uploaded file could not be stored in private storage.');
            }

            $storedSize = $disk->size($storagePath);
            $storedHash = $this->hashStoredFile($disk->readStream($storagePath));

            if ($storedSize !== $size || ! hash_equals($sourceHash, $storedHash)) {
                throw new RuntimeException('The stored upload did not match the validated source file.');
            }

            return $this->withIntakeLock($diskName, fn ($disk, array $directories): ImportSession => $this->registerSource([
                'source' => 'web',
                'original_filename' => $filename,
                'storage_disk' => $diskName,
                'storage_path' => $storagePath,
                'file_hash' => $storedHash,
                'file_size' => $storedSize,
                'file_type' => $extension,
                'tenant_id' => $tenantId,
            ], $actor));
        } catch (Throwable $exception) {
            $this->removeStoredUpload($disk, $temporaryPath, $storagePath);

            throw $exception;
        }
    }

    /**
     * Claim and register completed files from the configured private local inbox.
     * Invalid or incomplete entries are ignored and logged without exposing paths.
     * Discovery never assigns an importer, stages rows, or starts an import.
     *
     * @return list<ImportSession> Newly registered intake records.
     */
    public function discoverInbox(Authenticatable $actor, ?string $tenantId = null): array
    {
        $this->coordinator->authorizeIntakeCreation($actor, $tenantId);
        $diskName = (string) config('smart-import.storage.disk', 'local');
        $this->assertPrivateDisk($diskName);

        if ((config('filesystems.disks.'.$diskName.'.driver')) !== 'local') {
            throw new RuntimeException('SFTP inbox discovery requires a private local filesystem disk.');
        }

        return $this->withIntakeLock($diskName, function ($disk, array $directories) use ($diskName, $actor, $tenantId): array {
            $discovered = [];
            $inboxDirectory = $this->uploadDirectory();

            foreach (new \DirectoryIterator($directories['inbox']) as $entry) {
                $filename = $entry->getFilename();

                if ($entry->isDot() || str_starts_with($filename, '.') || str_ends_with(strtolower($filename), '.part')) {
                    continue;
                }

                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $sourcePath = $entry->getPathname();
                $canonicalPath = $inboxDirectory.'/'.$filename;

                if (strlen($canonicalPath) > 512) {
                    $this->logRejectedInboxEntry($filename, 'The inbox filename is too long to register safely.');

                    continue;
                }

                if (ImportSession::query()
                    ->where('storage_disk', $diskName)
                    ->where('storage_path', $canonicalPath)
                    ->exists()) {
                    continue;
                }

                if ($entry->isLink()) {
                    $session = $this->quarantineInboxEntry(
                        $diskName,
                        $directories,
                        $sourcePath,
                        $canonicalPath,
                        $filename,
                        $extension,
                        'Symbolic links are not accepted in the import inbox.',
                        $actor,
                        $tenantId,
                    );

                    if ($session !== null) {
                        $discovered[] = $session;
                    }

                    continue;
                }

                if ($entry->isDir()) {
                    continue;
                }

                if (! $entry->isFile()) {
                    $this->logRejectedInboxEntry($filename, 'The inbox entry is not a regular file.');

                    continue;
                }

                if (! isset(self::FORMATS[$extension])) {
                    $session = $this->quarantineInboxEntry(
                        $diskName,
                        $directories,
                        $sourcePath,
                        $canonicalPath,
                        $filename,
                        $extension,
                        'Only CSV, XLSX, and XLS files are accepted.',
                        $actor,
                        $tenantId,
                    );

                    if ($session !== null) {
                        $discovered[] = $session;
                    }

                    continue;
                }

                try {
                    $safeFilename = $this->safeFilename($filename);

                    if (! mb_check_encoding($safeFilename, 'UTF-8') || strlen($canonicalPath) > 512) {
                        throw new InvalidArgumentException('The inbox filename is invalid.');
                    }

                    $before = $this->assertStableRegularFile($sourcePath);
                    $size = $before['size'];
                    $maximumSize = (int) config('smart-import.uploads.max_file_size_bytes', 10 * 1024 * 1024);

                    if ($maximumSize < 1) {
                        throw new RuntimeException('The Smart Import maximum upload size must be greater than zero.');
                    }

                    if ($size < 1) {
                        throw new InvalidArgumentException('Empty files cannot be imported.');
                    }

                    if ($size > $maximumSize) {
                        throw new InvalidArgumentException('The inbox file exceeds the configured size limit.');
                    }

                    $this->assertSupportedContent($sourcePath, $extension);
                    $hash = hash_file('sha256', $sourcePath);

                    if (! is_string($hash)) {
                        throw new InvalidArgumentException('The inbox file could not be checksummed.');
                    }

                    $after = $this->assertStableRegularFile($sourcePath);

                    if ($before !== $after) {
                        throw new InvalidArgumentException('The inbox file changed while it was being checked.');
                    }

                    $claimedPath = $this->processingDirectory().'/'.Str::uuid().'.'.$extension;
                    $this->assertClaimPaths($directories, $sourcePath, $claimedPath);

                    $discovered[] = $this->registerSource([
                        'source' => 'sftp',
                        'original_filename' => $safeFilename,
                        'storage_disk' => $diskName,
                        'storage_path' => $canonicalPath,
                        'claimed_storage_path' => $claimedPath,
                        'file_hash' => $hash,
                        'file_size' => $size,
                        'file_type' => $extension,
                        'tenant_id' => $tenantId,
                    ], $actor, function () use ($sourcePath, $claimedPath, $directories, $size, $hash): void {
                        $this->claimInboxFile($directories, $sourcePath, $claimedPath, $size, $hash);
                    }, function () use ($directories, $sourcePath, $claimedPath): void {
                        $this->restoreInboxFile($directories, $sourcePath, $claimedPath);
                    }, IntakeStatus::Claimed);
                } catch (InvalidArgumentException $exception) {
                    $session = $this->quarantineInboxEntry(
                        $diskName,
                        $directories,
                        $sourcePath,
                        $canonicalPath,
                        $filename,
                        $extension,
                        $exception->getMessage(),
                        $actor,
                        $tenantId,
                    );

                    if ($session !== null) {
                        $discovered[] = $session;
                    }
                }
            }

            return $discovered;
        });
    }

    /** Reject an unstarted or review-ready source and move its retained copy to private rejected storage. */
    public function reject(string $sessionId, string $reason, Authenticatable $actor): ImportSession
    {
        $reason = $this->safeReason($reason);
        $diskName = (string) config('smart-import.storage.disk', 'local');
        $targetPath = $this->rejectedDirectory().'/'.Str::uuid().'.bin';

        return $this->moveSessionSource($sessionId, IntakeStatus::Rejected, $actor, $targetPath, [
            'error_summary' => $reason,
        ], function (ImportSession $session): void {
            if ($session->status !== null && $session->status !== ImportStatus::ReviewReady) {
                throw new \DomainException('Only unstarted or review-ready files can be rejected.');
            }
        }, $diskName);
    }

    /** Archive an eligible intake source without deleting its retained file. */
    public function archive(string $sessionId, Authenticatable $actor): ImportSession
    {
        $diskName = (string) config('smart-import.storage.disk', 'local');
        $targetPath = $this->archiveDirectory().'/'.Str::uuid().'.bin';

        return $this->moveSessionSource($sessionId, IntakeStatus::Archived, $actor, $targetPath, [], function (ImportSession $session): void {
            if (in_array($session->status, [ImportStatus::Staging, ImportStatus::Queued, ImportStatus::Importing, ImportStatus::Cancelling], true)) {
                throw new \DomainException('Active imports cannot be archived.');
            }
        }, $diskName);
    }

    /**
     * Quarantine an unsafe or unreadable SFTP entry and persist a rejected session.
     * Symlinks are moved as links and never opened or followed.
     *
     * @param  array{root: string, inbox: string, processing: string, quarantine: string, rejected: string, archive: string}  $directories
     */
    private function quarantineInboxEntry(
        string $diskName,
        array $directories,
        string $sourcePath,
        string $canonicalPath,
        string $filename,
        string $extension,
        string $reason,
        Authenticatable $actor,
        ?string $tenantId,
    ): ?ImportSession {
        $disk = Storage::disk($diskName);
        $quarantinePath = $this->quarantineDirectory().'/'.Str::uuid().'.bin';
        $sourceParent = $this->normalizePath(dirname($sourcePath));
        $targetAbsolutePath = $disk->path($quarantinePath);
        $targetParent = realpath(dirname($targetAbsolutePath));
        $sourceStat = @lstat($sourcePath);
        $sourceIsLink = is_link($sourcePath);
        $sourceIsRegular = is_array($sourceStat) && (($sourceStat['mode'] & 0170000) === 0100000);

        if ($sourceParent !== $directories['inbox'] || ! is_string($targetParent)
            || $this->normalizePath($targetParent) !== $directories['quarantine']
            || ! is_array($sourceStat) || (! $sourceIsLink && ! $sourceIsRegular)) {
            $this->logRejectedInboxEntry($filename, 'The inbox entry could not be safely quarantined.');

            return null;
        }

        $fileSize = max(0, (int) $sourceStat['size']);
        $maximumSize = (int) config('smart-import.uploads.max_file_size_bytes', 10 * 1024 * 1024);
        $fileHash = null;

        if ($sourceIsRegular && $fileSize <= max(0, $maximumSize)) {
            $fileHash = hash_file('sha256', $sourcePath);

            if (! is_string($fileHash)) {
                $fileHash = null;
            }
        }

        $safeExtension = preg_match('/\A[a-z0-9]{1,100}\z/i', $extension) === 1 ? strtolower($extension) : 'unknown';
        $safeReason = $this->safeReason($reason);

        return (function () use (
            $diskName,
            $disk,
            $sourcePath,
            $canonicalPath,
            $filename,
            $safeExtension,
            $safeReason,
            $fileSize,
            $fileHash,
            $quarantinePath,
            $sourceIsLink,
            $sourceStat,
            $actor,
            $tenantId,
        ): ?ImportSession {
            $existing = ImportSession::query()
                ->where('storage_disk', $diskName)
                ->where('storage_path', $canonicalPath)
                ->first();

            if ($existing !== null) {
                return null;
            }

            $targetPath = $disk->path($quarantinePath);
            $targetDirectoryStat = @stat(dirname($targetPath));
            $sourceDirectoryStat = @stat(dirname($sourcePath));

            if ($sourceDirectoryStat === false || $targetDirectoryStat === false
                || $sourceDirectoryStat['dev'] !== $targetDirectoryStat['dev']
                || file_exists($targetPath) || is_link($targetPath)) {
                throw new RuntimeException('The inbox entry could not be quarantined on the configured private filesystem.');
            }

            $moved = false;

            try {
                return DB::transaction(function () use (
                    $diskName,
                    $sourcePath,
                    $canonicalPath,
                    $filename,
                    $safeExtension,
                    $safeReason,
                    $fileSize,
                    $fileHash,
                    $quarantinePath,
                    $sourceIsLink,
                    $sourceStat,
                    $targetPath,
                    $actor,
                    $tenantId,
                    &$moved,
                ): ?ImportSession {
                    $recorded = ImportSession::query()
                        ->where('storage_disk', $diskName)
                        ->where('storage_path', $canonicalPath)
                        ->lockForUpdate()
                        ->exists();

                    if ($recorded) {
                        return null;
                    }

                    clearstatcache(true, $sourcePath);
                    $currentStat = @lstat($sourcePath);

                    if (! is_array($currentStat)
                        || (int) $currentStat['dev'] !== (int) $sourceStat['dev']
                        || (int) $currentStat['ino'] !== (int) $sourceStat['ino']
                        || (int) $currentStat['size'] !== $fileSize
                        || is_link($sourcePath) !== $sourceIsLink) {
                        throw new InvalidArgumentException('The inbox entry changed before it could be quarantined.');
                    }

                    if (! @rename($sourcePath, $targetPath)) {
                        throw new RuntimeException('The inbox entry could not be quarantined safely.');
                    }

                    $moved = true;
                    clearstatcache(true, $targetPath);
                    $quarantinedStat = @lstat($targetPath);

                    if (! is_array($quarantinedStat)
                        || (($quarantinedStat['mode'] & 0170000) === 0120000) !== $sourceIsLink
                        || (int) $quarantinedStat['size'] !== $fileSize) {
                        throw new RuntimeException('The quarantined entry did not match its original file metadata.');
                    }

                    if ($fileHash !== null) {
                        $quarantinedHash = hash_file('sha256', $targetPath);

                        if (! is_string($quarantinedHash) || ! hash_equals($fileHash, $quarantinedHash)) {
                            throw new RuntimeException('The quarantined file checksum changed during the move.');
                        }
                    }

                    $session = $this->coordinator->createRejected([
                        'source' => 'sftp',
                        'original_filename' => $this->safeRejectedFilename($filename),
                        'storage_disk' => $diskName,
                        'storage_path' => $canonicalPath,
                        'claimed_storage_path' => $quarantinePath,
                        'file_hash' => $fileHash,
                        'file_size' => $fileSize,
                        'file_type' => $safeExtension,
                        'tenant_id' => $tenantId,
                    ], $safeReason, $actor);

                    Log::warning('Smart Import quarantined an invalid inbox file.', [
                        'filename' => $session->original_filename,
                        'reason' => $safeReason,
                    ]);

                    return $session;
                }, 3);
            } catch (Throwable $exception) {
                if ($moved && (is_file($targetPath) || is_link($targetPath))) {
                    try {
                        $registered = ImportSession::query()
                            ->where('storage_disk', $diskName)
                            ->where('storage_path', $canonicalPath)
                            ->where('claimed_storage_path', $quarantinePath)
                            ->exists();

                        if (! $registered && ! file_exists($sourcePath) && ! is_link($sourcePath) && ! @rename($targetPath, $sourcePath)) {
                            Log::error('Smart Import could not restore a file after quarantine registration failed.');
                        }
                    } catch (Throwable) {
                        Log::error('Smart Import could not determine whether quarantined file registration completed; the retained file remains quarantined.');
                    }
                }

                throw $exception;
            }
        })();
    }

    /**
     * Move an existing private source under the session lock and update its lifecycle metadata atomically.
     *
     * @param  array<string, mixed>  $metadata
     * @param  callable(ImportSession): void  $eligibilityCheck
     */
    private function moveSessionSource(
        string $sessionId,
        IntakeStatus $next,
        Authenticatable $actor,
        string $targetPath,
        array $metadata,
        callable $eligibilityCheck,
        string $diskName,
    ): ImportSession {
        return $this->withIntakeLock($diskName, function ($disk, array $directories) use (
            $sessionId,
            $next,
            $actor,
            $targetPath,
            $metadata,
            $eligibilityCheck,
            $diskName,
        ): ImportSession {
            $sourcePath = null;
            $moved = false;

            try {
                return $this->coordinator->transitionIntakeWithMetadata(
                    $sessionId,
                    $next,
                    $actor,
                    array_merge($metadata, ['claimed_storage_path' => $targetPath]),
                    function (ImportSession $session) use ($eligibilityCheck, $diskName, $targetPath, $directories, &$sourcePath, &$moved): void {
                        $eligibilityCheck($session);
                        $sourcePath = $session->claimed_storage_path ?: $session->storage_path;
                        $this->moveManagedFile($session, $sourcePath, $targetPath, $diskName, $directories);
                        $moved = true;
                    },
                );
            } catch (Throwable $exception) {
                if ($moved && is_string($sourcePath)) {
                    try {
                        $session = ImportSession::query()->find($sessionId);

                        $transitionWasPersisted = $session !== null
                            && $session->intake_status === $next
                            && $session->claimed_storage_path === $targetPath;

                        if ($session !== null && ! $transitionWasPersisted) {
                            $this->moveManagedFile($session, $targetPath, $sourcePath, $diskName, $directories);
                        }
                    } catch (Throwable) {
                        Log::error('Smart Import could not determine whether a file lifecycle update completed; the retained file was left in place.');
                    }
                }

                throw $exception;
            }
        });
    }

    /**
     * Move a file between package-owned directories and verify its immutable identity at both paths.
     *
     * @param  array<string, string>  $directories
     */
    private function moveManagedFile(
        ImportSession $session,
        string $sourcePath,
        string $targetPath,
        string $diskName,
        array $directories,
    ): void {
        $this->assertManagedRelativePath($sourcePath);
        $this->assertManagedRelativePath($targetPath);
        $disk = Storage::disk($diskName);

        if ($disk->exists($targetPath) || (config('filesystems.disks.'.$diskName.'.driver') === 'local' && is_link($disk->path($targetPath)))) {
            throw new RuntimeException('The private lifecycle destination already exists.');
        }

        $sourceIsLink = false;

        if (config('filesystems.disks.'.$diskName.'.driver') === 'local') {
            $sourceAbsolute = $disk->path($sourcePath);
            $targetAbsolute = $disk->path($targetPath);
            $sourceParent = realpath(dirname($sourceAbsolute));
            $targetParent = realpath(dirname($targetAbsolute));
            $sourceStat = @lstat($sourceAbsolute);
            $sourceIsLink = is_link($sourceAbsolute);

            if (! is_string($sourceParent) || ! is_string($targetParent) || ! is_array($sourceStat)
                || ! $this->isManagedDirectory($this->normalizePath($sourceParent), $directories)
                || ! $this->isManagedDirectory($this->normalizePath($targetParent), $directories)
                || (! $sourceIsLink && (($sourceStat['mode'] & 0170000) !== 0100000))) {
                throw new RuntimeException('The private source file is unavailable or outside managed storage.');
            }

            if ((int) $sourceStat['size'] !== (int) $session->file_size) {
                throw new RuntimeException('The private source file size no longer matches its registered identity.');
            }

            if (! $sourceIsLink && $session->file_hash !== null) {
                $sourceHash = hash_file('sha256', $sourceAbsolute);

                if (! is_string($sourceHash) || ! hash_equals($session->file_hash, $sourceHash)) {
                    throw new RuntimeException('The private source checksum no longer matches its registered identity.');
                }
            }

            $sourceDirectoryStat = @stat(dirname($sourceAbsolute));
            $targetDirectoryStat = @stat(dirname($targetAbsolute));

            if ($sourceDirectoryStat === false || $targetDirectoryStat === false
                || $sourceDirectoryStat['dev'] !== $targetDirectoryStat['dev']
                || ! @rename($sourceAbsolute, $targetAbsolute)) {
                throw new RuntimeException('The private source file could not be moved safely.');
            }

            clearstatcache(true, $targetAbsolute);
            $targetStat = @lstat($targetAbsolute);

            if (! is_array($targetStat)
                || is_link($targetAbsolute) !== $sourceIsLink
                || (int) $targetStat['size'] !== (int) $session->file_size) {
                @rename($targetAbsolute, $sourceAbsolute);

                throw new RuntimeException('The moved file did not match its registered size and file type.');
            }

            if (! $sourceIsLink && $session->file_hash !== null) {
                $targetHash = hash_file('sha256', $targetAbsolute);

                if (! is_string($targetHash) || ! hash_equals($session->file_hash, $targetHash)) {
                    @rename($targetAbsolute, $sourceAbsolute);

                    throw new RuntimeException('The moved file checksum did not match its registered identity.');
                }
            }

            return;
        }

        if (! $disk->exists($sourcePath) || (int) $disk->size($sourcePath) !== (int) $session->file_size) {
            throw new RuntimeException('The private source file is unavailable or has changed size.');
        }

        if ($session->file_hash !== null) {
            $sourceHash = $this->hashStoredFile($disk->readStream($sourcePath));

            if (! hash_equals($session->file_hash, $sourceHash)) {
                throw new RuntimeException('The private source checksum no longer matches its registered identity.');
            }
        }

        $this->ensureRemoteLifecycleDirectory($disk, dirname($targetPath));

        if (! $disk->move($sourcePath, $targetPath)) {
            throw new RuntimeException('The private source file could not be moved.');
        }

        if (! $disk->exists($targetPath) || (int) $disk->size($targetPath) !== (int) $session->file_size) {
            $disk->move($targetPath, $sourcePath);

            throw new RuntimeException('The moved file did not match its registered size.');
        }

        if ($session->file_hash !== null) {
            $targetHash = $this->hashStoredFile($disk->readStream($targetPath));

            if (! hash_equals($session->file_hash, $targetHash)) {
                $disk->move($targetPath, $sourcePath);

                throw new RuntimeException('The moved file checksum did not match its registered identity.');
            }
        }
    }

    /** @param array<string, string> $directories */
    private function isManagedDirectory(string $path, array $directories): bool
    {
        return in_array($path, array_values($directories), true);
    }

    private function assertManagedRelativePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new RuntimeException('A private source path is invalid.');
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) {
                throw new RuntimeException('A private source path is invalid.');
            }
        }

        $allowedDirectories = [
            $this->uploadDirectory(),
            $this->processingDirectory(),
            $this->quarantineDirectory(),
            $this->rejectedDirectory(),
            $this->archiveDirectory(),
        ];

        if (! in_array(dirname($path), $allowedDirectories, true)) {
            throw new RuntimeException('A private source path is outside Smart Import storage.');
        }
    }

    private function ensureRemoteLifecycleDirectory(mixed $disk, string $relativeDirectory): void
    {
        if (! $disk->directoryExists($relativeDirectory)) {
            $disk->makeDirectory($relativeDirectory);
        }
    }

    private function safeRejectedFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?? '', " .\t\n\r\0\x0B");

        if ($filename === '') {
            $filename = 'quarantined-file';
        }

        if (! mb_check_encoding($filename, 'UTF-8')) {
            $filename = 'quarantined-file';
        }

        return mb_substr($filename, 0, 255, 'UTF-8');
    }

    private function safeReason(string $reason): string
    {
        $reason = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $reason) ?? '');

        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('A rejection reason between 1 and 1000 characters is required.');
        }

        return $reason;
    }

    private function consumeUploadQuota(ImportActorIdentity $identity): void
    {
        $maximum = (int) config('smart-import.uploads.max_files_per_actor_per_hour', 20);

        if ($maximum < 1) {
            throw new RuntimeException('The Smart Import per-actor upload quota must be greater than zero.');
        }

        $key = 'smart-import:upload:'.hash('sha256', $identity->type."\0".$identity->id);

        if (RateLimiter::tooManyAttempts($key, $maximum)) {
            throw new InvalidArgumentException('The upload limit for this account has been reached. Try again later.');
        }

        RateLimiter::hit($key, 3600);
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $basename = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $basename) ?? '', " .\t\n\r\0\x0B");

        if ($extension === '' || $basename === '') {
            throw new InvalidArgumentException('The uploaded filename is invalid.');
        }

        $basename = mb_substr($basename, 0, 254 - strlen($extension), 'UTF-8');

        return $basename.'.'.$extension;
    }

    private function assertSupportedContent(string $path, string $extension): void
    {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! is_string($mimeType) || ! in_array(strtolower($mimeType), self::FORMATS[$extension]['mime_types'], true)) {
            throw new InvalidArgumentException('The uploaded file content does not match its declared file type.');
        }

        try {
            $reader = IOFactory::identify($path, [
                IOFactory::READER_CSV,
                IOFactory::READER_XLSX,
                IOFactory::READER_XLS,
            ]);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The uploaded file is not a readable CSV, XLSX, or XLS file.', 0, $exception);
        }

        if ($reader !== self::FORMATS[$extension]['reader']) {
            throw new InvalidArgumentException('The uploaded file extension does not match its spreadsheet content.');
        }
    }

    private function assertPrivateDisk(string $diskName): void
    {
        if (trim($diskName) === '' || strtolower($diskName) === 'public') {
            throw new RuntimeException('Smart Import must use a configured private storage disk.');
        }

        $diskConfig = config('filesystems.disks.'.$diskName);

        if (! is_array($diskConfig)) {
            throw new RuntimeException('The configured Smart Import storage disk does not exist.');
        }

        if (strtolower((string) ($diskConfig['visibility'] ?? 'private')) !== 'private') {
            throw new RuntimeException('Smart Import cannot write uploads to a public storage disk.');
        }

        if (($diskConfig['driver'] ?? null) === 'local') {
            $root = $diskConfig['root'] ?? null;

            if (! is_string($root) || trim($root) === '') {
                throw new RuntimeException('The configured private local storage disk must have a root path.');
            }

            $localRoots = array_unique([
                $this->normalizePath($root),
                $this->normalizePath($root, true),
            ]);
            $publicRoots = [public_path(), storage_path('app/public')];
            $publicRoots = array_unique(array_merge(
                array_map(fn (string $path): string => $this->normalizePath($path), $publicRoots),
                array_map(fn (string $path): string => $this->normalizePath($path, true), $publicRoots),
            ));

            foreach ($localRoots as $localRoot) {
                foreach ($publicRoots as $publicRoot) {
                    if ($this->pathIsWithin($localRoot, $publicRoot)) {
                        throw new RuntimeException('Smart Import cannot write uploads to a web-accessible local path.');
                    }
                }
            }
        }
    }

    private function uploadDirectory(): string
    {
        $root = $this->storageRootDirectory();

        $directory = $root === '' ? 'inbox' : $root.'/inbox';

        if (strlen($directory) + 1 + 36 + 1 + 4 > 512) {
            throw new RuntimeException('The Smart Import storage root is too long.');
        }

        return $directory;
    }

    private function processingDirectory(): string
    {
        $root = $this->storageRootDirectory();

        return $root === '' ? 'processing' : $root.'/processing';
    }

    private function quarantineDirectory(): string
    {
        $root = $this->storageRootDirectory();

        return $root === '' ? 'quarantine' : $root.'/quarantine';
    }

    private function rejectedDirectory(): string
    {
        $root = $this->storageRootDirectory();

        return $root === '' ? 'rejected' : $root.'/rejected';
    }

    private function archiveDirectory(): string
    {
        $root = $this->storageRootDirectory();

        return $root === '' ? 'archive' : $root.'/archive';
    }

    private function storageRootDirectory(): string
    {
        $root = (string) config('smart-import.storage.root', 'smart-import');

        if (str_starts_with($root, '/') || str_starts_with($root, '\\') || str_contains($root, "\0")) {
            throw new RuntimeException('The Smart Import storage root must be a relative directory.');
        }

        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $root)), fn (string $segment): bool => $segment !== ''));

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || str_contains($segment, ':')) {
                throw new RuntimeException('The Smart Import storage root contains an unsafe path segment.');
            }
        }

        return implode('/', $segments);
    }

    /**
     * Serialize intake registration using the host's lock-capable cache store and initialize
     * package-owned directories for local private disks.
     *
     * @param  callable(mixed, array<string, string>): mixed  $callback
     */
    private function withIntakeLock(string $diskName, callable $callback): mixed
    {
        $this->assertPrivateDisk($diskName);
        $disk = Storage::disk($diskName);

        $directories = (config('filesystems.disks.'.$diskName.'.driver')) === 'local'
            ? $this->ensureLocalDirectories($diskName)
            : [];
        $store = Cache::getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Smart Import intake requires a cache store that supports atomic locks.');
        }

        $lockIdentity = implode("\0", [
            $diskName,
            (string) config('filesystems.disks.'.$diskName.'.driver'),
            (string) config('filesystems.disks.'.$diskName.'.root', ''),
            (string) config('filesystems.disks.'.$diskName.'.bucket', ''),
            (string) config('smart-import.storage.root', 'smart-import'),
        ]);

        return Cache::lock('smart-import:intake:'.hash('sha256', $lockIdentity), 3600)
            ->block(30, fn (): mixed => $callback($disk, $directories));
    }

    /**
     * @return array{root: string, inbox: string, processing: string, quarantine: string, rejected: string, archive: string}
     */
    private function ensureLocalDirectories(string $diskName): array
    {
        $disk = Storage::disk($diskName);
        $root = $this->storageRootDirectory();
        $inbox = $this->uploadDirectory();
        $processing = $this->processingDirectory();
        $quarantine = $this->quarantineDirectory();
        $rejected = $this->rejectedDirectory();
        $archive = $this->archiveDirectory();

        foreach (array_unique(array_filter([$root, $inbox, $processing, $quarantine, $rejected, $archive], fn (string $path): bool => $path !== '')) as $directory) {
            $absolute = $disk->path($directory);

            if (is_link($absolute)) {
                throw new RuntimeException('A Smart Import storage directory cannot be a symbolic link.');
            }

            if (! is_dir($absolute)) {
                $disk->makeDirectory($directory);
            }

            if (is_link($absolute) || ! is_dir($absolute)) {
                throw new RuntimeException('A Smart Import storage directory is unavailable.');
            }
        }

        $diskRoot = realpath($disk->path(''));
        $rootPath = realpath($root === '' ? $disk->path('') : $disk->path($root));
        $inboxPath = realpath($disk->path($inbox));
        $processingPath = realpath($disk->path($processing));
        $quarantinePath = realpath($disk->path($quarantine));
        $rejectedPath = realpath($disk->path($rejected));
        $archivePath = realpath($disk->path($archive));

        if (! is_string($diskRoot) || ! is_string($rootPath) || ! is_string($inboxPath) || ! is_string($processingPath)
            || ! is_string($quarantinePath) || ! is_string($rejectedPath) || ! is_string($archivePath)
            || ! $this->pathIsWithin($this->normalizePath($rootPath), $this->normalizePath($diskRoot))
            || ! $this->pathIsWithin($this->normalizePath($inboxPath), $this->normalizePath($rootPath))
            || ! $this->pathIsWithin($this->normalizePath($processingPath), $this->normalizePath($rootPath))
            || ! $this->pathIsWithin($this->normalizePath($quarantinePath), $this->normalizePath($rootPath))
            || ! $this->pathIsWithin($this->normalizePath($rejectedPath), $this->normalizePath($rootPath))
            || ! $this->pathIsWithin($this->normalizePath($archivePath), $this->normalizePath($rootPath))) {
            throw new RuntimeException('Smart Import storage directories must remain beneath the configured private disk root.');
        }

        return [
            'root' => $this->normalizePath($rootPath),
            'inbox' => $this->normalizePath($inboxPath),
            'processing' => $this->normalizePath($processingPath),
            'quarantine' => $this->normalizePath($quarantinePath),
            'rejected' => $this->normalizePath($rejectedPath),
            'archive' => $this->normalizePath($archivePath),
        ];
    }

    /**
     * Register a validated source and, for SFTP, atomically move it into immutable processing storage.
     *
     * @param  array<string, mixed>  $attributes
     * @param  callable(): void|null  $claim
     * @param  callable(): void|null  $restore
     */
    private function registerSource(
        array $attributes,
        Authenticatable $actor,
        ?callable $claim = null,
        ?callable $restore = null,
        IntakeStatus $intakeStatus = IntakeStatus::Discovered,
    ): ImportSession {
        return DB::transaction(function () use ($attributes, $actor, $claim, $restore, $intakeStatus): ImportSession {
            $existing = ImportSession::query()
                ->where('storage_disk', $attributes['storage_disk'])
                ->where('storage_path', $attributes['storage_path'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $duplicate = ImportSession::query()
                ->where('file_hash', $attributes['file_hash'])
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();
            $claimed = false;

            try {
                if ($claim !== null) {
                    $claim();
                    $claimed = true;
                }

                $session = $this->coordinator->create(array_merge($attributes, [
                    'possible_duplicate_of' => $duplicate?->getKey(),
                ]), $actor);

                if ($intakeStatus !== IntakeStatus::Discovered) {
                    $session->transitionIntakeTo($intakeStatus);
                    $session->save();
                }

                return $session;
            } catch (Throwable $exception) {
                if ($claimed && $restore !== null) {
                    $restore();
                }

                throw $exception;
            }
        }, 3);
    }

    /**
     * @param  array{root: string, inbox: string, processing: string, quarantine: string, rejected: string, archive: string}  $directories
     */
    private function assertClaimPaths(array $directories, string $sourcePath, string $claimedPath): void
    {
        $sourceRealPath = realpath($sourcePath);
        $targetPath = Storage::disk((string) config('smart-import.storage.disk', 'local'))->path($claimedPath);
        $targetDirectory = realpath(dirname($targetPath));

        if (! is_string($sourceRealPath) || ! is_string($targetDirectory)
            || ! $this->pathIsWithin($this->normalizePath($sourceRealPath), $directories['inbox'])
            || $this->normalizePath($targetDirectory) !== $directories['processing']) {
            throw new InvalidArgumentException('The inbox file path is outside the configured private intake directories.');
        }
    }

    /**
     * @param  array{root: string, inbox: string, processing: string, quarantine: string, rejected: string, archive: string}  $directories
     */
    private function claimInboxFile(
        array $directories,
        string $sourcePath,
        string $claimedPath,
        int $expectedSize,
        string $expectedHash,
    ): void {
        $diskName = (string) config('smart-import.storage.disk', 'local');
        $targetPath = Storage::disk($diskName)->path($claimedPath);
        $this->assertClaimPaths($directories, $sourcePath, $claimedPath);
        $sourceStat = $this->assertStableRegularFile($sourcePath);
        $sourceDirectoryStat = @stat(dirname($sourcePath));
        $targetDirectoryStat = @stat($directories['processing']);

        if ($sourceStat['size'] !== $expectedSize || $sourceDirectoryStat === false || $targetDirectoryStat === false
            || $sourceDirectoryStat['dev'] !== $targetDirectoryStat['dev']) {
            throw new InvalidArgumentException('The inbox file is not stable on the configured private filesystem.');
        }

        if (file_exists($targetPath) || is_link($targetPath) || ! @rename($sourcePath, $targetPath)) {
            throw new RuntimeException('The completed inbox file could not be claimed safely.');
        }

        try {
            clearstatcache(true, $targetPath);
            $targetStat = $this->assertStableRegularFile($targetPath);
            $targetRealPath = realpath($targetPath);
            $targetHash = hash_file('sha256', $targetPath);

            if (! is_string($targetRealPath)
                || ! $this->pathIsWithin($this->normalizePath($targetRealPath), $directories['processing'])
                || $targetStat['size'] !== $expectedSize
                || ! is_string($targetHash)
                || ! hash_equals($expectedHash, $targetHash)) {
                throw new InvalidArgumentException('The claimed inbox file did not match its validated checksum and size.');
            }
        } catch (Throwable $exception) {
            $this->restoreInboxFile($directories, $sourcePath, $claimedPath);

            throw $exception;
        }
    }

    /**
     * @param  array{root: string, inbox: string, processing: string, quarantine: string, rejected: string, archive: string}  $directories
     */
    private function restoreInboxFile(array $directories, string $sourcePath, string $claimedPath): void
    {
        $targetPath = Storage::disk((string) config('smart-import.storage.disk', 'local'))->path($claimedPath);

        if (is_file($targetPath) && ! is_link($targetPath) && ! file_exists($sourcePath) && ! is_link($sourcePath)) {
            if (! @rename($targetPath, $sourcePath)) {
                Log::error('Smart Import could not restore a claimed file after an intake failure.');
            }
        }
    }

    /** @return array{device: int, inode: int, size: int, modified: int} */
    private function assertStableRegularFile(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);

        if (is_link($path) || ! is_array($stat) || (($stat['mode'] & 0170000) !== 0100000)) {
            throw new InvalidArgumentException('The inbox entry is not a regular file.');
        }

        return [
            'device' => (int) $stat['dev'],
            'inode' => (int) $stat['ino'],
            'size' => (int) $stat['size'],
            'modified' => (int) $stat['mtime'],
        ];
    }

    private function logRejectedInboxEntry(string $filename, ?string $reason = null): void
    {
        $safeName = preg_replace('/[\x00-\x1F\x7F]/', '', basename(str_replace('\\', '/', $filename))) ?? 'unknown';
        $safeName = mb_substr($safeName, 0, 120, 'UTF-8');

        Log::warning('Smart Import ignored an invalid private inbox entry.', [
            'filename' => $safeName,
            'reason' => $reason ?? 'The entry is not a regular file.',
        ]);
    }

    private function normalizePath(string $path, bool $resolveSymlinks = false): string
    {
        $path = str_replace('\\', '/', $path);

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        $segments = [];

        foreach (explode('/', preg_replace('~/+~', '/', $path) ?: $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalized = '/'.implode('/', $segments);

        if ($resolveSymlinks) {
            $realPath = realpath($normalized);

            if (is_string($realPath)) {
                return $this->normalizePath($realPath);
            }
        }

        return rtrim($normalized, '/') ?: '/';
    }

    private function pathIsWithin(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, rtrim($directory, '/').'/');
    }

    /** @param resource|false $stream */
    private function hashStoredFile($stream): string
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('The stored upload could not be verified.');
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    private function removeStoredUpload($disk, string $temporaryPath, string $storagePath): void
    {
        foreach ([$temporaryPath, $storagePath] as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }
}
