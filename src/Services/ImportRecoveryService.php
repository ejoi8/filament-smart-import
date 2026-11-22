<?php

namespace SmartImport\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use SmartImport\Enums\ImportStatus;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Models\ImportSession;
use Throwable;

final class ImportRecoveryService
{
    private const FINAL_RECOVERY_ERRORS = [
        'Saved row outcomes do not match the durable execution counters. Review this import before retrying.',
        'The configured safe recovery limit was reached. Check queue worker health before manually retrying this import.',
        'The configured staging recovery limit was reached. Check the file and queue worker before trying again.',
    ];

    public function __construct(
        private ImportExecutionLifecycle $lifecycle,
        private ImportExecutionDispatcher $dispatcher,
        private ImportPreviewService $previews,
        private ImportOutboxService $outbox,
        private ImportAuditService $audit,
    ) {}

    /** @return array{scanned: int, stage_requeued: int, execution_requeued: int, preview_requeued: int, failed: int, cancelled: int, completed: int, would_recover: int, would_fail: int, skipped: int} */
    public function recoverStale(int $limit = 100, bool $dryRun = false): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Smart Import recovery limit must be between 1 and 500 sessions.');
        }

        $staleAfter = (int) config('smart-import.recovery.stale_after_seconds', 900);
        $maxRecoveries = (int) config('smart-import.recovery.max_recoveries', 3);

        if ($staleAfter < 1 || $staleAfter > 604800 || $maxRecoveries < 1 || $maxRecoveries > 10) {
            throw new InvalidArgumentException('Smart Import recovery settings require a stale timeout of 1–604800 seconds and 1–10 recovery cycles.');
        }

        $cutoff = now()->subSeconds($staleAfter);
        $ids = ImportSession::query()
            ->where(function ($eligible) use ($maxRecoveries): void {
                $eligible->where('status', ImportStatus::Staging->value)
                    ->orWhere(function ($activeExecution): void {
                        $activeExecution->whereIn('status', [
                            ImportStatus::Queued->value,
                            ImportStatus::Importing->value,
                            ImportStatus::Cancelling->value,
                        ])
                            ->whereNotNull('confirmed_revision')
                            ->whereColumn('confirmed_revision', 'staging_revision')
                            ->where('current_attempt', '>', 0)
                            ->where('execution_total', '>', 0);
                    })
                    ->orWhere(function ($preview): void {
                        $preview->where('status', ImportStatus::ReviewReady->value)
                            ->where('options->preview_state', 'building');
                    })
                    ->orWhere(function ($failed) use ($maxRecoveries): void {
                        $failed->where('status', ImportStatus::Failed->value)
                            ->where(function ($recoveryWindow) use ($maxRecoveries): void {
                                $recoveryWindow->where('recovery_count', '<', $maxRecoveries)
                                    ->orWhere(function ($exhausted) use ($maxRecoveries): void {
                                        $exhausted->where('recovery_count', '>=', $maxRecoveries)
                                            ->where(function ($terminalFailure): void {
                                                $terminalFailure->whereNull('error_summary')
                                                    ->orWhereNotIn('error_summary', self::FINAL_RECOVERY_ERRORS);
                                            });
                                    });
                            })
                            ->where(function ($recoverable): void {
                                $recoverable->where(function ($staging): void {
                                    $staging->whereNull('confirmed_revision')
                                        ->whereNotNull('importer_key')
                                        ->whereNotNull('importer_version')
                                        ->whereNotNull('sheet_index')
                                        ->whereNotNull('sheet_name')
                                        ->whereNotNull('mapping');
                                })->orWhere(function ($execution): void {
                                    $execution->whereNotNull('confirmed_revision')
                                        ->whereColumn('confirmed_revision', 'staging_revision')
                                        ->where('current_attempt', '>', 0)
                                        ->where('execution_total', '>', 0);
                                });
                            });
                    });
            })
            ->where(function ($query) use ($cutoff): void {
                $query->where('heartbeat_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('heartbeat_at')->where('updated_at', '<', $cutoff);
                    });
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');

        $counts = [
            'scanned' => $ids->count(),
            'stage_requeued' => 0,
            'execution_requeued' => 0,
            'preview_requeued' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'completed' => 0,
            'would_recover' => 0,
            'would_fail' => 0,
            'skipped' => 0,
        ];

        foreach ($ids as $id) {
            $session = ImportSession::query()->find($id);

            $outcome = $session?->status === ImportStatus::ReviewReady
                ? $this->previews->recoverStalePreview((string) $id, $cutoff, $maxRecoveries, $dryRun)
                : $this->recoverOne((string) $id, $cutoff, $maxRecoveries, $dryRun);
            $counts[$outcome]++;
        }

        return $counts;
    }

    private function recoverOne(string $sessionId, mixed $cutoff, int $maxRecoveries, bool $dryRun): string
    {
        return DB::transaction(function () use ($sessionId, $cutoff, $maxRecoveries, $dryRun): string {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if ($session === null || ! $this->isStale($session, $cutoff)) {
                return 'skipped';
            }

            if ($session->status === ImportStatus::Cancelling) {
                if ($dryRun) {
                    return 'would_recover';
                }

                $this->lifecycle->completeIfReady(
                    (string) $session->getKey(),
                    $session->current_attempt,
                    (int) $session->confirmed_revision,
                );

                return match ($session->fresh()->status) {
                    ImportStatus::Completed => 'completed',
                    ImportStatus::Cancelled => 'cancelled',
                    ImportStatus::Failed => 'failed',
                    default => 'skipped',
                };
            }

            if ($session->status === ImportStatus::Staging
                || ($session->status === ImportStatus::Failed && $session->confirmed_revision === null)) {
                return $this->recoverStaging($session, $maxRecoveries, $dryRun);
            }

            if (! in_array($session->status, [ImportStatus::Queued, ImportStatus::Importing, ImportStatus::Failed], true)
                || $session->confirmed_revision === null
                || $session->confirmed_revision !== $session->staging_revision
                || $session->current_attempt < 1
                || $session->execution_total < 1) {
                return 'skipped';
            }

            $pending = $session->rows()->where('execution_status', 'pending')->count();
            $expectedPending = $session->execution_total - $session->processed;

            if ($session->processed < 0
                || $session->processed > $session->execution_total
                || $pending !== $expectedPending) {
                if ($dryRun) {
                    return 'would_fail';
                }

                $this->lifecycle->failRecovery(
                    (string) $session->getKey(),
                    $session->current_attempt,
                    $session->confirmed_revision,
                    'Saved row outcomes do not match the durable execution counters. Review this import before retrying.',
                );

                return 'failed';
            }

            if ($pending === 0 && $session->processed === $session->execution_total) {
                if ($dryRun) {
                    return 'would_recover';
                }

                $this->lifecycle->completeIfReady(
                    (string) $session->getKey(),
                    $session->current_attempt,
                    $session->confirmed_revision,
                );

                return match ($session->fresh()->status) {
                    ImportStatus::Completed => 'completed',
                    ImportStatus::Failed => 'failed',
                    default => 'skipped',
                };
            }

            if ($session->recovery_count >= $maxRecoveries) {
                if ($dryRun) {
                    return 'would_fail';
                }

                $this->lifecycle->failRecovery(
                    (string) $session->getKey(),
                    $session->current_attempt,
                    $session->confirmed_revision,
                    'The configured safe recovery limit was reached. Check queue worker health before manually retrying this import.',
                );

                return 'failed';
            }

            if ($dryRun) {
                return 'would_recover';
            }

            $this->dispatcher->assertQueueCanRunAsynchronously();

            $wasFailed = $session->status === ImportStatus::Failed;

            if ($wasFailed) {
                $this->outbox->supersedeDeferred($session);
                $session->transitionTo(ImportStatus::Queued);
            }

            $session->forceFill([
                'recovery_count' => $session->recovery_count + 1,
                'heartbeat_at' => now(),
                'error_summary' => null,
                'finished_at' => $wasFailed ? null : $session->finished_at,
            ]);
            $session->save();
            $this->audit->record($session, 'import.recovery_requeued', reasonCode: 'stale_execution_requeued', metadata: [
                'attempt_id' => max(0, (int) $session->current_attempt),
                'recovery_count' => (int) $session->recovery_count,
                'stage' => 'execution',
            ]);

            $sessionId = (string) $session->getKey();
            $attemptId = $session->current_attempt;
            $revision = $session->confirmed_revision;
            $session->getConnection()->afterCommit(fn () => $this->dispatcher->dispatchConfirmed(
                $sessionId,
                $attemptId,
                $revision,
            ));

            return 'execution_requeued';
        }, 3);
    }

    private function recoverStaging(ImportSession $session, int $maxRecoveries, bool $dryRun): string
    {
        if ($session->importer_key === null
            || $session->importer_version === null
            || $session->sheet_index === null
            || $session->sheet_name === null
            || $session->mapping === null) {
            if (! $dryRun) {
                $this->failStaging($session, 'The saved staging configuration is incomplete. Review the file and mapping before restarting staging.');
            }

            return $dryRun ? 'would_fail' : 'failed';
        }

        if ($session->recovery_count >= $maxRecoveries) {
            if (! $dryRun) {
                $this->failStaging($session, 'The configured staging recovery limit was reached. Check the file and queue worker before trying again.');
            }

            return $dryRun ? 'would_fail' : 'failed';
        }

        if ($dryRun) {
            return 'would_recover';
        }

        $this->dispatcher->assertQueueCanRunAsynchronously();

        if ($session->status === ImportStatus::Failed) {
            $this->outbox->supersedeDeferred($session);
            $session->transitionTo(ImportStatus::Staging);
        }

        $session->forceFill([
            'recovery_count' => $session->recovery_count + 1,
            'heartbeat_at' => now(),
            'finished_at' => null,
            'error_summary' => null,
        ]);
        $session->save();
        $this->audit->record($session, 'import.recovery_requeued', reasonCode: 'stale_staging_requeued', metadata: [
            'attempt_id' => max(0, (int) $session->current_attempt),
            'recovery_count' => (int) $session->recovery_count,
            'stage' => 'staging',
        ]);

        $sessionId = (string) $session->getKey();
        $mappingRevision = $session->mapping_revision;
        $stagingRevision = $session->staging_revision;
        $queue = config('smart-import.queue.name');
        $connection = config('smart-import.queue.connection');
        $session->getConnection()->afterCommit(function () use ($sessionId, $mappingRevision, $stagingRevision, $queue, $connection): void {
            $job = new StageImportJob($sessionId, $mappingRevision, $stagingRevision);

            if (is_string($queue) && $queue !== '') {
                $job->onQueue($queue);
            }

            if (is_string($connection) && $connection !== '') {
                $job->onConnection($connection);
            }

            try {
                dispatch($job);
            } catch (Throwable $exception) {
                app(ImportStagingService::class)->fail($sessionId, $mappingRevision, $stagingRevision);
                Log::error('Smart Import staging recovery dispatch failed.', [
                    'import_id' => $sessionId,
                    'exception' => $exception::class,
                ]);
            }
        });

        return 'stage_requeued';
    }

    private function failStaging(ImportSession $session, string $message): void
    {
        if ($session->status === ImportStatus::Staging) {
            $session->transitionTo(ImportStatus::Failed);
        }

        $session->forceFill([
            'error_summary' => $message,
            'heartbeat_at' => now(),
            'finished_at' => now(),
        ]);
        $session->save();
        $this->outbox->recordTerminal($session);
        $this->audit->record($session, 'import.recovery_failed', reasonCode: 'staging_recovery_failed', metadata: [
            'attempt_id' => max(0, (int) $session->current_attempt),
            'recovery_count' => (int) $session->recovery_count,
        ]);
        $this->audit->record($session, 'import.failed', reasonCode: 'staging_recovery_failed', metadata: [
            'status' => ImportStatus::Failed->value,
            'attempt_id' => max(0, (int) $session->current_attempt),
            'processed' => (int) $session->processed,
            'created' => (int) $session->created_count,
            'updated' => (int) $session->updated_count,
            'skipped' => (int) $session->skipped_count,
            'failed' => (int) $session->failed_count,
        ]);
    }

    private function isStale(ImportSession $session, mixed $cutoff): bool
    {
        $lastActivity = $session->heartbeat_at ?? $session->updated_at;

        return $lastActivity !== null && $lastActivity->lessThan($cutoff);
    }
}
