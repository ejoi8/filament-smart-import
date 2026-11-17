<?php

namespace SmartImport\Services;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;

final class ImportProgressService
{
    public const RECENT_ERROR_LIMIT = 10;

    public function __construct(
        private ImportCoordinator $imports,
        private ImportAuthorization $authorization,
    ) {}

    /**
     * Read the latest persisted status for an actor-visible session.
     *
     * Spreadsheet values and stored error text are deliberately excluded. Errors use a
     * fixed safe message so malformed/custom persisted text cannot expose row contents.
     *
     * @return array<string, mixed>
     */
    public function get(string $sessionId, Authenticatable $actor): array
    {
        $session = $this->imports->get($sessionId, $actor);
        $status = $session->status;
        $execution = $this->executionProgress($session);
        $startedAt = $session->started_at;
        $finishedAt = $session->finished_at;
        $lastActivity = $this->lastActivity($session);
        $durationSeconds = $startedAt === null
            ? null
            : max(0, ($finishedAt ?? now())->getTimestamp() - $startedAt->getTimestamp());

        $recentErrors = $session->rows()
            ->where('execution_status', 'failed')
            ->whereNotNull('last_error_code')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_ERROR_LIMIT)
            ->get(['id', 'source_row_number', 'last_error_code'])
            ->map(static fn (ImportRow $row): array => [
                'row' => $row->source_row_number,
                'code' => $row->last_error_code,
                'message' => 'The row could not be processed under the confirmed import rules.',
            ])
            ->all();

        return [
            'id' => (string) $session->getKey(),
            'filename' => $session->original_filename,
            'source' => $session->source,
            'status' => $status?->value,
            'can_cancel' => in_array($status, [ImportStatus::Queued, ImportStatus::Importing], true)
                && $this->authorization->allows('cancel', $actor, $session),
            'can_retry' => $status === ImportStatus::Completed
                && (int) $session->failed_count > 0
                && $this->authorization->allows('retry', $actor, $session),
            'attempt' => (int) $session->current_attempt,
            'stage' => [
                'status' => $status?->value,
                'processed' => (int) $session->stage_processed,
                'total' => (int) $session->stage_total,
                'valid' => (int) $session->stage_valid,
                'invalid' => (int) $session->stage_invalid,
                'warnings' => (int) $session->stage_warning,
            ],
            'execution' => $execution,
            'timestamps' => [
                'started' => $session->started_at?->toIso8601String(),
                'heartbeat' => $session->heartbeat_at?->toIso8601String(),
                'finished' => $session->finished_at?->toIso8601String(),
                'last_activity' => $lastActivity?->toIso8601String(),
                'duration_seconds' => $durationSeconds,
            ],
            'recent_errors' => $recentErrors,
        ];
    }

    /**
     * List every active session visible to this actor with counters from committed database state.
     *
     * @return list<array<string, mixed>>
     */
    public function active(Authenticatable $actor): array
    {
        $sessions = $this->authorization
            ->scopeSessions(ImportSession::query(), $actor)
            ->whereIn('status', [
                ImportStatus::Staging->value,
                ImportStatus::Queued->value,
                ImportStatus::Importing->value,
                ImportStatus::Cancelling->value,
            ])
            ->orderByDesc('updated_at')
            ->get();

        return $sessions
            ->filter(fn (ImportSession $session): bool => $this->authorization->allows('view', $actor, $session))
            ->map(function (ImportSession $session): array {
                $execution = $this->executionProgress($session);
                $stageTotal = max(0, (int) $session->stage_total);
                $stageProcessed = min($stageTotal, max(0, (int) $session->stage_processed));
                $isStaging = $session->status === ImportStatus::Staging;

                return [
                    'id' => (string) $session->getKey(),
                    'filename' => $session->original_filename,
                    'source' => $session->source,
                    'status' => $session->status?->value,
                    'attempt' => (int) $session->current_attempt,
                    'progress' => [
                        'percentage' => $isStaging
                            ? ($stageTotal === 0 ? 0 : (int) floor(($stageProcessed / $stageTotal) * 100))
                            : $execution['percentage'],
                        'processed' => $isStaging ? $stageProcessed : $execution['processed'],
                        'total' => $isStaging ? $stageTotal : $execution['total'],
                        'label' => $isStaging ? 'rows staged' : 'rows processed',
                    ],
                    'last_activity' => $this->lastActivity($session)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{percentage: int, processed: int, total: int, remaining: int, created: int, updated: int, skipped: int, failed: int} */
    private function executionProgress(ImportSession $session): array
    {
        $total = max(0, (int) $session->execution_total);
        $processed = min($total, max(0, (int) $session->processed));

        return [
            'percentage' => $total === 0
                ? ($session->status === ImportStatus::Completed ? 100 : 0)
                : (int) floor(($processed / $total) * 100),
            'processed' => $processed,
            'total' => $total,
            'remaining' => max(0, $total - $processed),
            'created' => (int) $session->created_count,
            'updated' => (int) $session->updated_count,
            'skipped' => (int) $session->skipped_count,
            'failed' => (int) $session->failed_count,
        ];
    }

    private function lastActivity(ImportSession $session): ?DateTimeInterface
    {
        return $session->heartbeat_at ?? $session->started_at ?? $session->queued_at;
    }
}
