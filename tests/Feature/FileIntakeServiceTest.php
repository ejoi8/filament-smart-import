<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportAuditEntry;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\SpreadsheetReader;
use SmartImport\Tests\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileIntakeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Gate::define('create', fn (): bool => true);
        Gate::define('update', fn (): bool => true);
    }

    #[Test]
    public function valid_csv_is_registered_with_a_random_private_path_and_verified_identity(): void
    {
        $file = LaravelUploadedFile::fake()->createWithContent('contacts.csv', "name,email\nAda,ada@example.test\n");

        $session = $this->intake()->upload($file, new GenericUser(['id' => 7]));

        $this->assertSame('web', $session->source);
        $this->assertSame('contacts.csv', $session->original_filename);
        $this->assertSame('csv', $session->file_type);
        $this->assertSame(strlen("name,email\nAda,ada@example.test\n"), $session->file_size);
        $this->assertSame(hash_file('sha256', $file->getPathname()), $session->file_hash);
        $this->assertNull($session->importer_key);
        $this->assertNull($session->status);
        $this->assertStringStartsWith('smart-import/inbox/', $session->storage_path);
        $this->assertStringEndsWith('.csv', $session->storage_path);
        $this->assertMatchesRegularExpression('~\Asmart-import/inbox/[a-f0-9-]{36}\.csv\z~', $session->storage_path);
        $this->assertStringNotContainsString('contacts.csv', $session->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($session->storage_path));
        $partialFiles = array_filter(
            Storage::disk('local')->allFiles('smart-import/inbox'),
            fn (string $path): bool => str_ends_with($path, '.part'),
        );
        $this->assertSame([], $partialFiles);
    }

    #[Test]
    public function repeated_upload_content_is_retained_and_marked_as_a_possible_duplicate(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $contents = "name,email\nAda,ada@example.test\n";
        $first = $this->intake()->upload(LaravelUploadedFile::fake()->createWithContent('first.csv', $contents), $actor);
        $second = $this->intake()->upload(LaravelUploadedFile::fake()->createWithContent('second.csv', $contents), $actor);

        $this->assertNull($first->possible_duplicate_of);
        $this->assertSame($first->getKey(), $second->possible_duplicate_of);
        $this->assertNotSame($first->storage_path, $second->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($first->storage_path));
        $this->assertTrue(Storage::disk('local')->exists($second->storage_path));
    }

    #[Test]
    public function discovery_ignores_partial_files_claims_completed_files_and_is_idempotent(): void
    {
        $disk = Storage::disk('local');
        $contents = "name,email\nAda,ada@example.test\n";
        $disk->put('smart-import/inbox/contacts.csv', $contents);
        $disk->put('smart-import/inbox/incomplete.csv.part', $contents);
        $disk->put('smart-import/inbox/.hidden.csv', $contents);
        $disk->put('smart-import/inbox/nested/ignored.csv', $contents);
        $actor = new GenericUser(['id' => 7]);

        $sessions = $this->intake()->discoverInbox($actor);

        $this->assertCount(1, $sessions);
        $session = $sessions[0];
        $this->assertSame('sftp', $session->source);
        $this->assertSame('contacts.csv', $session->original_filename);
        $this->assertSame('smart-import/inbox/contacts.csv', $session->storage_path);
        $this->assertMatchesRegularExpression('~\Asmart-import/processing/[a-f0-9-]{36}\.csv\z~', $session->claimed_storage_path);
        $this->assertSame(IntakeStatus::Claimed, $session->intake_status);
        $this->assertNull($session->importer_key);
        $this->assertNull($session->status);
        $this->assertSame(0, $session->rows()->count());
        $this->assertFalse($disk->exists($session->storage_path));
        $this->assertTrue($disk->exists($session->claimed_storage_path));
        $this->assertTrue($disk->exists('smart-import/inbox/incomplete.csv.part'));
        $this->assertSame([], $this->intake()->discoverInbox($actor));
        $this->assertDatabaseCount('smart_imports', 1);

        $sheets = $this->app->make(SpreadsheetReader::class)->listSheets($session);
        $this->assertSame(['Worksheet'], array_column($sheets, 'name'));
    }

    #[Test]
    public function invalid_spreadsheet_content_is_not_registered_or_claimed(): void
    {
        $disk = Storage::disk('local');
        $disk->put('smart-import/inbox/not-a-workbook.xlsx', "name,email\nAda,ada@example.test\n");

        $sessions = $this->intake()->discoverInbox(new GenericUser(['id' => 7]));

        $this->assertCount(1, $sessions);
        $session = $sessions[0];
        $this->assertSame(IntakeStatus::Rejected, $session->intake_status);
        $this->assertSame('The uploaded file content does not match its declared file type.', $session->error_summary);
        $this->assertSame('smart-import/inbox/not-a-workbook.xlsx', $session->storage_path);
        $this->assertStringStartsWith('smart-import/quarantine/', $session->claimed_storage_path);
        $this->assertNotNull($session->file_hash);
        $this->assertFalse($disk->exists($session->storage_path));
        $this->assertTrue($disk->exists($session->claimed_storage_path));
        $this->assertSame([], $disk->allFiles('smart-import/processing'));
        $this->assertSame(
            ['source' => 'sftp', 'file_type' => 'xlsx'],
            ImportAuditEntry::query()->where('import_id', $session->getKey())->sole()->metadata,
        );
    }

    #[Test]
    public function oversized_sftp_file_is_quarantined_without_fabricating_a_checksum(): void
    {
        config()->set('smart-import.uploads.max_file_size_bytes', 8);
        $disk = Storage::disk('local');
        $contents = "name,email\nAda,ada@example.test\n";
        $disk->put('smart-import/inbox/too-large.csv', $contents);

        $sessions = $this->intake()->discoverInbox(new GenericUser(['id' => 7]));

        $this->assertCount(1, $sessions);
        $session = $sessions[0];
        $this->assertSame(IntakeStatus::Rejected, $session->intake_status);
        $this->assertStringContainsString('size limit', $session->error_summary);
        $this->assertNull($session->file_hash);
        $this->assertSame(strlen($contents), $session->file_size);
        $this->assertTrue($disk->exists($session->claimed_storage_path));
        $this->assertFalse($disk->exists($session->storage_path));
    }

    #[Test]
    public function symlink_inbox_entry_is_quarantined_without_following_its_target(): void
    {
        $disk = Storage::disk('local');
        $targetRelativePath = 'smart-import/processing/keep.csv';
        $targetPath = $disk->path($targetRelativePath);
        $linkPath = $disk->path('smart-import/inbox/link.csv');
        $disk->makeDirectory('smart-import/inbox');
        $disk->put($targetRelativePath, "name\nAda\n");
        symlink($targetPath, $linkPath);

        $sessions = $this->intake()->discoverInbox(new GenericUser(['id' => 7]));

        $this->assertCount(1, $sessions);
        $session = $sessions[0];
        $quarantinePath = $disk->path($session->claimed_storage_path);
        $this->assertSame(IntakeStatus::Rejected, $session->intake_status);
        $this->assertSame('Symbolic links are not accepted in the import inbox.', $session->error_summary);
        $this->assertNull($session->file_hash);
        $this->assertTrue(is_link($quarantinePath));
        $this->assertFileExists($targetPath);
        $this->assertFalse(is_link($linkPath));
    }

    #[Test]
    public function rejection_and_archive_move_the_retained_source_without_changing_its_identity(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $file = LaravelUploadedFile::fake()->createWithContent('contacts.csv', "name,email\nAda,ada@example.test\n");
        $session = $this->intake()->upload($file, $actor);
        $originalHash = $session->file_hash;
        $originalCanonicalPath = $session->storage_path;

        $rejected = $this->intake()->reject((string) $session->getKey(), 'This file belongs to another dataset.', $actor);

        $this->assertSame(IntakeStatus::Rejected, $rejected->intake_status);
        $this->assertSame('This file belongs to another dataset.', $rejected->error_summary);
        $this->assertSame($originalHash, $rejected->file_hash);
        $this->assertSame($originalCanonicalPath, $rejected->storage_path);
        $this->assertStringStartsWith('smart-import/rejected/', $rejected->claimed_storage_path);
        $this->assertFalse(Storage::disk('local')->exists($originalCanonicalPath));
        $this->assertTrue(Storage::disk('local')->exists($rejected->claimed_storage_path));

        $archived = $this->intake()->archive((string) $session->getKey(), $actor);
        $this->assertSame(IntakeStatus::Archived, $archived->intake_status);
        $this->assertSame($originalHash, $archived->file_hash);
        $this->assertStringStartsWith('smart-import/archive/', $archived->claimed_storage_path);
        $this->assertTrue(Storage::disk('local')->exists($archived->claimed_storage_path));

        $this->expectException(DomainException::class);
        app(ImportCoordinator::class)->transition((string) $session->getKey(), ImportStatus::Queued, $actor);
    }

    #[Test]
    public function archive_refuses_a_source_whose_registered_identity_has_changed(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->intake()->upload(
            LaravelUploadedFile::fake()->createWithContent('contacts.csv', "name\nAda\n"),
            $actor,
        );
        Storage::disk('local')->put($session->storage_path, "name\nEve\n");

        try {
            $this->intake()->archive((string) $session->getKey(), $actor);
            $this->fail('A changed source must not be moved as if its registered identity were intact.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('checksum', $exception->getMessage());
        }

        $fresh = $session->fresh();
        $this->assertNull($fresh->claimed_storage_path);
        $this->assertSame(IntakeStatus::Discovered, $fresh->intake_status);
        $this->assertTrue(Storage::disk('local')->exists($session->storage_path));
    }

    #[Test]
    public function active_import_cannot_be_rejected_or_archived(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $file = LaravelUploadedFile::fake()->createWithContent('contacts.csv', "name,email\nAda,ada@example.test\n");
        $session = $this->intake()->upload($file, $actor);
        $coordinator = app(ImportCoordinator::class);
        $coordinator->transition((string) $session->getKey(), ImportStatus::Uploaded, $actor);
        $coordinator->transition((string) $session->getKey(), ImportStatus::Staging, $actor);

        try {
            $this->intake()->reject((string) $session->getKey(), 'Stop this import.', $actor);
            $this->fail('An active import must not be rejected.');
        } catch (DomainException) {
            $this->assertTrue(Storage::disk('local')->exists($session->storage_path));
        }

        try {
            $this->intake()->archive((string) $session->getKey(), $actor);
            $this->fail('An active import must not be archived.');
        } catch (DomainException) {
            $this->assertTrue(Storage::disk('local')->exists($session->storage_path));
        }
    }

    #[Test]
    public function rejecting_a_file_requires_update_permission_and_does_not_move_it_on_denial(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->intake()->upload(
            LaravelUploadedFile::fake()->createWithContent('contacts.csv', "name\nAda\n"),
            $actor,
        );
        Gate::define('update', fn (): bool => false);

        $this->expectException(AuthorizationException::class);

        try {
            $this->intake()->reject((string) $session->getKey(), 'Not permitted.', $actor);
        } finally {
            $this->assertTrue(Storage::disk('local')->exists($session->storage_path));
            $this->assertNull($session->fresh()->error_summary);
        }
    }

    #[Test]
    public function unauthorized_discovery_does_not_claim_or_register_files(): void
    {
        $disk = Storage::disk('local');
        $disk->put('smart-import/inbox/contacts.csv', "name,email\nAda,ada@example.test\n");
        Gate::define('create', fn (): bool => false);

        try {
            $this->intake()->discoverInbox(new GenericUser(['id' => 7]));
            $this->fail('Discovery must require intake creation permission.');
        } catch (AuthorizationException) {
            $this->assertTrue($disk->exists('smart-import/inbox/contacts.csv'));
            $this->assertDatabaseCount('smart_imports', 0);
        }
    }

    #[Test]
    public function xlsx_and_xls_uploads_must_match_the_detected_spreadsheet_reader(): void
    {
        foreach ([['xlsx', Xlsx::class], ['xls', Xls::class]] as [$extension, $writer]) {
            $file = $this->spreadsheetUpload($extension, $writer);

            try {
                $session = $this->intake()->upload($file, new GenericUser(['id' => 7]));

                $this->assertSame($extension, $session->file_type);
                $this->assertSame($extension, pathinfo($session->storage_path, PATHINFO_EXTENSION));
                $this->assertTrue(Storage::disk('local')->exists($session->storage_path));
            } finally {
                @unlink($file->getPathname());
            }
        }
    }

    #[Test]
    public function content_mismatch_is_rejected_without_creating_a_session_or_file(): void
    {
        $file = LaravelUploadedFile::fake()->createWithContent('contacts.xlsx', "name,email\nAda,ada@example.test\n");

        try {
            $this->intake()->upload($file, new GenericUser(['id' => 7]));
            $this->fail('A CSV payload with an XLSX extension must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('content', $exception->getMessage());
        }

        $this->assertDatabaseCount('smart_imports', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function spreadsheet_extension_and_mime_are_not_enough_without_a_matching_parser(): void
    {
        $path = sys_get_temp_dir().'/smart-import-invalid-'.uniqid('', true).'.xlsx';
        $archive = new \ZipArchive();
        $archive->open($path, \ZipArchive::CREATE);
        $archive->addFromString('payload.txt', 'This is not an Excel workbook.');
        $archive->close();
        $file = new UploadedFile($path, 'invalid.xlsx', null, UPLOAD_ERR_OK, true);

        try {
            $this->intake()->upload($file, new GenericUser(['id' => 7]));
            $this->fail('A generic ZIP archive must not pass XLSX parser validation.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('readable', $exception->getMessage());
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseCount('smart_imports', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function upload_size_limit_is_checked_against_the_actual_temporary_file(): void
    {
        config()->set('smart-import.uploads.max_file_size_bytes', 8);
        $file = LaravelUploadedFile::fake()->createWithContent('contacts.csv', "name,email\nAda,ada@example.test\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('size limit');

        $this->intake()->upload($file, new GenericUser(['id' => 7]));
    }

    #[Test]
    public function empty_files_are_rejected(): void
    {
        $file = LaravelUploadedFile::fake()->createWithContent('empty.csv', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty or unreadable');

        $this->intake()->upload($file, new GenericUser(['id' => 7]));
    }

    #[Test]
    public function unsupported_extensions_are_rejected(): void
    {
        $file = LaravelUploadedFile::fake()->createWithContent('contacts.txt', "name,email\nAda,ada@example.test\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only CSV, XLSX, and XLS');

        $this->intake()->upload($file, new GenericUser(['id' => 7]));
    }

    #[Test]
    public function macro_enabled_xlsm_workbooks_are_rejected_as_unsupported(): void
    {
        $file = LaravelUploadedFile::fake()->createWithContent('contacts.xlsm', "name,email\nAda,ada@example.test\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only CSV, XLSX, and XLS');

        $this->intake()->upload($file, new GenericUser(['id' => 7]));
    }

    #[Test]
    public function public_disks_and_local_paths_under_public_roots_are_rejected(): void
    {
        $file = LaravelUploadedFile::fake()->createWithContent('contacts.csv', "name,email\nAda,ada@example.test\n");
        config()->set('smart-import.storage.disk', 'public');

        try {
            $this->intake()->upload($file, new GenericUser(['id' => 7]));
            $this->fail('The named public disk must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('private storage', $exception->getMessage());
        }

        config()->set('smart-import.storage.disk', 'exposed');
        config()->set('filesystems.disks.exposed', [
            'driver' => 'local',
            'root' => public_path('smart-import-test'),
            'visibility' => 'private',
            'throw' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('web-accessible local path');

        $this->intake()->upload($file, new GenericUser(['id' => 7]));
    }

    #[Test]
    public function a_disk_configured_with_public_visibility_is_rejected(): void
    {
        $file = LaravelUploadedFile::fake()->createWithContent('contacts.csv', "name\nAda\n");
        config()->set('smart-import.storage.disk', 'public-visibility');
        config()->set('filesystems.disks.public-visibility', [
            'driver' => 'local',
            'root' => storage_path('app/private/smart-import-test'),
            'visibility' => 'public',
            'throw' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('public storage disk');

        $this->intake()->upload($file, new GenericUser(['id' => 7]));
    }

    #[Test]
    public function per_actor_upload_quota_is_enforced(): void
    {
        config()->set('smart-import.uploads.max_files_per_actor_per_hour', 1);
        $actor = new GenericUser(['id' => 7]);

        $this->intake()->upload(
            LaravelUploadedFile::fake()->createWithContent('first.csv', "name\nAda\n"),
            $actor,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('upload limit');

        $this->intake()->upload(
            LaravelUploadedFile::fake()->createWithContent('second.csv', "name\nGrace\n"),
            $actor,
        );
    }

    #[Test]
    public function authorization_failure_removes_the_stored_file(): void
    {
        Gate::define('create', fn (): bool => false);
        $file = LaravelUploadedFile::fake()->createWithContent('contacts.csv', "name,email\nAda,ada@example.test\n");

        $this->expectException(AuthorizationException::class);

        try {
            $this->intake()->upload($file, new GenericUser(['id' => 7]));
        } finally {
            $this->assertDatabaseCount('smart_imports', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    private function intake(): FileIntakeService
    {
        return $this->app->make(FileIntakeService::class);
    }

    /** @param class-string<Xls|Xlsx> $writer */
    private function spreadsheetUpload(string $extension, string $writer): UploadedFile
    {
        $path = sys_get_temp_dir().'/smart-import-'.uniqid('', true).'.'.$extension;
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['name'], ['Ada']]);
        (new $writer($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'contacts.'.$extension, null, UPLOAD_ERR_OK, true);
    }
}
