<?php

namespace SmartImport\Services;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;

final class ImportRetryService
{
    private const RETRY_BATCH_SIZE = 250;

    public function __construct(
        private ImportAuthorization $authorization,
        private ImporterRegistry $importers,
        private ImportExecutionDispatcher $dispatcher,
        private ImportAuditService $audit,
    ) {}

    /** Start a new attempt containing only failed rows from a fully completed attempt. */
    public function retryFailedRows(string $sessionId, Authenticatable $actor): ImportSession
    {
        return DB::transaction(function () use ($sessionId, $actor): ImportSession {
            $session = $this->authorization
                ->applyActorScope(ImportSession::query()->lockForUpdate(), $actor)
                ->whereKey($sessionId)
                ->firstOrFail();

            $this->authorization->authorize('view', $actor, $session);
            $this->authorization->authorize('retry', $actor, $session);

            if ($session->status !== ImportStatus::Completed) {
                throw new DomainException('Failed rows can be retried only after a completed attempt.');
            }

            if ($session->confirmed_revision === null
                || $session->confirmed_revision !== $session->staging_revision
                || $session->processed !== $session->execution_total) {
                throw new DomainException('The previous attempt is not a complete, confirmed snapshot; retry was not started.');
            }

            if ($session->importer_key === null || $session->importer_version === null) {
                throw new DomainException('The confirmed importer configuration is unavailable.');
            }

            $definition = $this->importers->get($session->importer_key);

            if ($definition->version() !== $session->importer_version
                || $definition->targetModel() !== $session->target_model) {
                throw new DomainException('The importer definition changed after confirmation; failed rows cannot be retried safely.');
            }

            $this->authorization->authorize('useImporter', $actor, $definition);
            $this->dispatcher->assertQueueCanRunAsynchronously();

            $failedRows = $session->rows()
                ->where('execution_status', 'failed');
            $retryTotal = (clone $failedRows)->count();

            if ($retryTotal < 1 || $retryTotal !== $session->failed_count) {
                throw new DomainException('The failed row count does not match the completed attempt; retry was not started.');
            }

            if ($session->rows()->whereNotIn('execution_status', ['created', 'updated', 'skipped', 'failed'])->exists()) {
                throw new DomainException('A failed-only retry requires every row in the previous attempt to have a terminal outcome.');
            }

            $attemptId = $session->current_attempt + 1;
            $queuedAt = now();
            $attempts = $session->attempt_history ?? [];
            $attempts[] = [
                'attempt' => $attemptId,
                'confirmed_revision' => $session->confirmed_revision,
                'execution_total' => $retryTotal,
                'retry' => 'failed_rows_only',
                'retry_of_attempt' => $session->current_attempt,
                'queued_at' => $queuedAt->toISOString(),
            ];

            $resetCount = 0;
            $failedRows
                ->orderBy('id')
                ->lockForUpdate()
                ->chunkById(self::RETRY_BATCH_SIZE, function ($rows) use ($session, &$resetCount): void {
                    $rowIds = $rows->modelKeys();
                    $updated = $session->rows()
                        ->whereIn('id', $rowIds)
                        ->where('execution_status', 'failed')
                        ->update([
                            'execution_status' => 'pending',
                            'last_error_code' => null,
                            'last_error_message' => null,
                            'completed_at' => null,
                            'updated_at' => now(),
                        ]);

                    if ($updated !== count($rowIds)) {
                        throw new DomainException('The failed row set changed while a retry was being prepared. No retry was started.');
                    }

                    $resetCount += $updated;
                });

            if ($resetCount !== $retryTotal) {
                throw new DomainException('The failed row set changed while a retry was being prepared. No retry was started.');
            }

            $session->forceFill([
                'current_attempt' => $attemptId,
                'recovery_count' => 0,
                'execution_total' => $retryTotal,
                'processed' => 0,
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 0,
                'attempt_history' => $attempts,
                'cancel_requested_at' => null,
                'queued_at' => $queuedAt,
                'started_at' => null,
                'heartbeat_at' => now(),
                'finished_at' => null,
                'error_summary' => null,
            ]);
            $session->transitionTo(ImportStatus::Queued);
            $session->save();
            $this->audit->record($session, 'import.retry_started', $actor, 'failed_rows_retry', [
                'attempt_id' => $attemptId,
                'retry_of_attempt' => $attemptId - 1,
                'execution_total' => $retryTotal,
            ]);

            $session->getConnection()->afterCommit(fn () => $this->dispatcher->dispatchConfirmed(
                (string) $session->getKey(),
                $attemptId,
                (int) $session->confirmed_revision,
            ));

            return $session;
        }, 3);
    }
}
