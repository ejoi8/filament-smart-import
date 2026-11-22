<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use Throwable;

final class ImportRetentionService
{
    private const TERMINAL_STATUSES = [
        ImportStatus::Completed->value,
        ImportStatus::Failed->value,
        ImportStatus::Cancelled->value,
    ];

    private const FINAL_RECOVERY_ERRORS = [
        'Saved row outcomes do not match the durable execution counters. Review this import before retrying.',
        'The configured safe recovery limit was reached. Check queue worker health before manually retrying this import.',
        'The configured staging recovery limit was reached. Check the file and queue worker before trying again.',
    ];

    private const CURSOR_VERSION = 1;

    private const MAX_CURSOR_LENGTH = 4096;

    private const CURSOR_LIFETIME_SECONDS = 604800;

    public function __construct(
        private ImportAuthorization $authorization,
        private ImportAuditService $audit,
    ) {}

    /**
     * Inspect a bounded page of old imports. This method only reads state and never removes files or records.
     *
     * @return array<string, mixed>
     */
    public function inspect(int $olderThanDays, int $limit = 100, ?string $cursor = null): array
    {
        if ($olderThanDays < 1 || $olderThanDays > 3650 || $limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Retention inspection requires 1–3650 days and a page limit of 1–500 imports.');
        }

        $state = $cursor === null
            ? $this->newScanState($olderThanDays)
            : $this->decodeCursor($cursor, $olderThanDays);
        $cutoff = $this->parseTimestamp($state['cutoff']);
        $query = ImportSession::query()
            ->where('created_at', '<=', $state['cutoff']);

        if ($state['upper'] !== null) {
            $query->where(function (Builder $query) use ($state): void {
                $query->where('created_at', '<', $state['upper']['created_at'])
                    ->orWhere(function (Builder $query) use ($state): void {
                        $query->where('created_at', '=', $state['upper']['created_at'])
                            ->where('id', '<=', $state['upper']['id']);
                    });
            });
        }

        if ($state['after'] !== null) {
            $query->where(function (Builder $query) use ($state): void {
                $query->where('created_at', '>', $state['after']['created_at'])
                    ->orWhere(function (Builder $query) use ($state): void {
                        $query->where('created_at', '=', $state['after']['created_at'])
                            ->where('id', '>', $state['after']['id']);
                    });
            });
        }

        $page = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get([
                'id', 'tenant_id', 'source', 'file_type', 'file_size', 'storage_disk', 'storage_path',
                'claimed_storage_path', 'status', 'finished_at', 'created_at', 'current_attempt',
                'recovery_count', 'execution_total', 'processed', 'failed_count', 'error_summary', 'retention_hold_at',
            ]);
        $hasMore = $page->count() > $limit;
        $sessions = $page->take($limit)->values();
        $ids = $sessions->modelKeys();
        $undelivered = DB::table('smart_import_outbox')
            ->whereIn('import_id', $ids)
            ->whereNotIn('delivery_status', ['delivered', 'superseded'])
            ->get(['import_id', 'delivery_status', 'lease_expires_at'])
            ->groupBy('import_id');
        $rowCounts = ImportRow::query()
            ->whereIn('import_id', $ids)
            ->selectRaw('import_id, count(*) as total')
            ->selectRaw("sum(case when execution_status = 'pending' then 1 else 0 end) as pending")
            ->selectRaw("sum(case when execution_status = 'failed' then 1 else 0 end) as failed")
            ->groupBy('import_id')
            ->get()
            ->keyBy('import_id');

        $eligible = [];
        $protected = [];

        foreach ($sessions as $session) {
            $counts = $rowCounts->get($session->getKey());
            $rowTotal = (int) ($counts?->total ?? 0);
            $pendingRows = (int) ($counts?->pending ?? 0);
            $failedRows = (int) ($counts?->failed ?? 0);
            $reason = $this->protectionReason($session, $pendingRows, $failedRows, $undelivered->get($session->getKey(), collect()));

            if ($reason !== null) {
                $protected[] = [
                    'session_id' => (string) $session->getKey(),
                    'status' => $session->status?->value ?? 'unassigned',
                    'reason' => $reason,
                ];

                continue;
            }

            $candidate = [
                'session_id' => (string) $session->getKey(),
                'status' => $session->status?->value,
                'created_at' => $session->created_at?->toIso8601String(),
                'finished_at' => $session->finished_at?->toIso8601String(),
                'file_type' => $session->file_type,
                'file_size' => (int) $session->file_size,
                'staged_rows' => $rowTotal,
            ];
            $sourcePath = $session->claimed_storage_path ?: $session->storage_path;

            if (Storage::disk($session->storage_disk)->exists($sourcePath)) {
                $eligible[] = $candidate + ['object' => 'source_file'];
            } else {
                $protected[] = [
                    'session_id' => (string) $session->getKey(),
                    'status' => $session->status?->value,
                    'reason' => 'source_file_missing',
                ];
            }

            if ($rowTotal > 0) {
                $eligible[] = $candidate + ['object' => 'staged_rows'];
            }
        }

        $report = [
            'dry_run' => true,
            'cutoff' => $cutoff->toIso8601String(),
            'scanned_imports' => $sessions->count(),
            'has_more' => $hasMore,
            'eligible_object_count' => count($eligible),
            'eligible_objects' => $eligible,
            'protected_import_count' => count($protected),
            'protected_imports' => $protected,
            'session_summaries' => 'retained',
            'audit_history' => 'retained',
        ];

        if ($hasMore && $sessions->isNotEmpty()) {
            $last = $sessions->last();
            $report['next_cursor'] = $this->encodeCursor($state, [
                'created_at' => $this->normalizeTimestamp($last->getRawOriginal('created_at')),
                'id' => (string) $last->getKey(),
            ]);
        }

        return $report;
    }

    /** @return array{version: int, older_than_days: int, cutoff: string, issued_at: int, upper: array{created_at: string, id: string}|null, after: array{created_at: string, id: string}|null} */
    private function newScanState(int $olderThanDays): array
    {
        $issuedAt = now();
        $cutoff = $issuedAt->copy()->subDays($olderThanDays)->format('Y-m-d H:i:s');
        $upper = DB::table((new ImportSession())->getTable())
            ->where('created_at', '<=', $cutoff)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['created_at', 'id']);

        return [
            'version' => self::CURSOR_VERSION,
            'older_than_days' => $olderThanDays,
            'cutoff' => $cutoff,
            'issued_at' => $issuedAt->timestamp,
            'upper' => $upper === null ? null : [
                'created_at' => $this->normalizeTimestamp($upper->created_at),
                'id' => (string) $upper->id,
            ],
            'after' => null,
        ];
    }

    /**
     * @param array{version: int, older_than_days: int, cutoff: string, issued_at: int, upper: array{created_at: string, id: string}|null, after: array{created_at: string, id: string}|null} $state
     * @param array{created_at: string, id: string} $after
     */
    private function encodeCursor(array $state, array $after): string
    {
        $state['after'] = $after;

        try {
            return Crypt::encryptString(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The retention continuation cursor could not be created.', 0, $exception);
        }
    }

    /** @return array{version: int, older_than_days: int, cutoff: string, issued_at: int, upper: array{created_at: string, id: string}|null, after: array{created_at: string, id: string}|null} */
    private function decodeCursor(string $cursor, int $olderThanDays): array
    {
        if (strlen($cursor) > self::MAX_CURSOR_LENGTH) {
            throw new InvalidArgumentException('The retention cursor is invalid or expired; restart the inspection.');
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('The retention cursor is invalid or expired; restart the inspection.');
        }

        if (! is_array($decoded)
            || ($decoded['version'] ?? null) !== self::CURSOR_VERSION
            || ($decoded['older_than_days'] ?? null) !== $olderThanDays
            || ! is_int($decoded['issued_at'] ?? null)
            || $decoded['issued_at'] > now()->timestamp
            || $decoded['issued_at'] < now()->timestamp - self::CURSOR_LIFETIME_SECONDS
            || ! $this->isValidTimestamp($decoded['cutoff'] ?? null)
            || ! is_array($decoded['upper'] ?? null)
            || ! $this->isValidTuple($decoded['upper'])
            || ! is_array($decoded['after'] ?? null)
            || ! $this->isValidTuple($decoded['after'])
            || $this->compareTuples($decoded['after'], $decoded['upper']) > 0) {
            throw new InvalidArgumentException('The retention cursor is invalid, expired, or does not match the requested age filter; restart the inspection.');
        }

        return $decoded;
    }

    private function isValidTimestamp(mixed $timestamp): bool
    {
        if (! is_string($timestamp) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $timestamp) !== 1) {
            return false;
        }

        try {
            return $this->parseTimestamp($timestamp)->format('Y-m-d H:i:s') === $timestamp;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $tuple */
    private function isValidTuple(array $tuple): bool
    {
        return count($tuple) === 2
            && $this->isValidTimestamp($tuple['created_at'] ?? null)
            && is_string($tuple['id'] ?? null)
            && Str::isUuid($tuple['id']);
    }

    private function parseTimestamp(string $timestamp): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d H:i:s', $timestamp, config('app.timezone'));
    }

    private function normalizeTimestamp(mixed $timestamp): string
    {
        if ($timestamp instanceof \DateTimeInterface) {
            return Carbon::instance($timestamp)->format('Y-m-d H:i:s');
        }

        if (! is_string($timestamp) || ! $this->isValidTimestamp($timestamp)) {
            throw new InvalidArgumentException('Smart Import found an invalid session timestamp during retention inspection.');
        }

        return $timestamp;
    }

    /** @param array{created_at: string, id: string} $left
     *  @param array{created_at: string, id: string} $right
     */
    private function compareTuples(array $left, array $right): int
    {
        $createdAt = strcmp($left['created_at'], $right['created_at']);

        return $createdAt !== 0 ? $createdAt : strcmp($left['id'], $right['id']);
    }

    public function placeHold(string $sessionId, Authenticatable $actor, string $reason): ImportSession
    {
        $reason = $this->validateHoldReason($reason);

        return DB::transaction(function () use ($sessionId, $actor, $reason): ImportSession {
            $session = $this->authorizedSession($sessionId, $actor, true);
            $this->authorization->authorize('manageRetention', $actor, $session);

            if ($session->retention_hold_at === null || $session->retention_hold_reason !== $reason) {
                $session->forceFill([
                    'retention_hold_at' => now(),
                    'retention_hold_reason' => $reason,
                ])->save();
                $this->audit->record($session, 'retention.hold_set', $actor, 'operator_requested', ['hold_active' => true]);
            }

            return $session;
        }, 3);
    }

    public function releaseHold(string $sessionId, Authenticatable $actor): bool
    {
        return DB::transaction(function () use ($sessionId, $actor): bool {
            $session = $this->authorizedSession($sessionId, $actor, true);
            $this->authorization->authorize('manageRetention', $actor, $session);

            if ($session->retention_hold_at === null) {
                return false;
            }

            $session->forceFill(['retention_hold_at' => null, 'retention_hold_reason' => null])->save();
            $this->audit->record($session, 'retention.hold_released', $actor, 'operator_requested', ['hold_active' => false]);

            return true;
        }, 3);
    }

    private function authorizedSession(string $sessionId, Authenticatable $actor, bool $lock): ImportSession
    {
        $query = ImportSession::query();

        if ($lock) {
            $query->lockForUpdate();
        }

        $session = $this->authorization->applyActorScope($query, $actor)->whereKey($sessionId)->firstOrFail();
        $this->authorization->authorize('view', $actor, $session);

        return $session;
    }

    private function protectionReason(ImportSession $session, int $pendingRows, int $failedRows, mixed $outboxEvents): ?string
    {
        if ($session->retention_hold_at !== null) {
            return 'manual_retention_hold';
        }

        if (! in_array($session->status?->value, self::TERMINAL_STATUSES, true)) {
            return 'active_or_unfinished_import';
        }

        if ($session->status === ImportStatus::Failed) {
            return in_array($session->error_summary, self::FINAL_RECOVERY_ERRORS, true)
                ? 'failed_import_retained_for_review'
                : 'failed_import_may_be_recoverable';
        }

        if ($pendingRows > 0 || (int) $session->processed < (int) $session->execution_total) {
            return 'unprocessed_rows';
        }

        if ($failedRows > 0 || (int) $session->failed_count > 0) {
            return 'failed_rows_may_be_retried';
        }

        if ($outboxEvents->isNotEmpty()) {
            return $outboxEvents->contains(static fn (object $event): bool => $event->delivery_status === 'leased')
                ? 'notification_delivery_lease_or_retry'
                : 'notification_delivery_unsettled';
        }

        return null;
    }

    private function validateHoldReason(string $reason): string
    {
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $reason) ?? '');

        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 191) {
            throw new InvalidArgumentException('A retention hold reason must be non-empty and at most 191 characters.');
        }

        return $reason;
    }
}
