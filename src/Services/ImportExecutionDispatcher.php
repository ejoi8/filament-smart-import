<?php

namespace SmartImport\Services;

use DomainException;
use Illuminate\Support\Facades\Log;
use SmartImport\Enums\ImportStatus;
use SmartImport\Jobs\ExecuteImportChunkJob;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use Throwable;

final class ImportExecutionDispatcher
{
    public function __construct(private ImportExecutionLifecycle $lifecycle) {}

    /** Reject real background execution when the selected queue would run inline. */
    public function assertQueueCanRunAsynchronously(): void
    {
        $connection = config('smart-import.queue.connection') ?: config('queue.default');

        if (! is_string($connection) || $connection === '') {
            throw new DomainException('Configure a Laravel queue connection before confirming an import.');
        }

        $driver = config("queue.connections.{$connection}.driver");

        if (! is_string($driver) || $driver === '') {
            throw new DomainException('The configured Smart Import queue connection is not available.');
        }

        if (in_array($driver, ['sync', 'null'], true)) {
            throw new DomainException('Smart Import requires an asynchronous Laravel queue connection; the sync driver would block the request.');
        }

        $chunkSize = (int) config('smart-import.execution.chunk_size', 100);
        $tries = (int) config('smart-import.execution.job_tries', 3);
        $backoff = config('smart-import.execution.job_backoff_seconds', [10, 30, 60]);

        if ($chunkSize < 1 || $chunkSize > 1000 || $tries < 1 || $tries > 10) {
            throw new DomainException('Smart Import execution settings require a chunk size of 1–1000 rows and 1–10 job tries.');
        }

        if (! is_array($backoff)
            || ! array_is_list($backoff)
            || count($backoff) > 8
            || array_filter($backoff, static fn (mixed $seconds): bool => ! is_int($seconds) || $seconds < 0 || $seconds > 86400) !== []) {
            throw new DomainException('Smart Import execution backoff must contain no more than eight delays from 0 to 86400 seconds.');
        }
    }

    /** Dispatch identifier-only chunks for an already committed execution snapshot. */
    public function dispatchConfirmed(string $sessionId, int $attemptId, int $confirmedRevision): void
    {
        $session = ImportSession::query()->find($sessionId);

        if ($session === null
            || ! in_array($session->status, [ImportStatus::Queued, ImportStatus::Importing], true)
            || $session->current_attempt !== $attemptId
            || $session->confirmed_revision !== $confirmedRevision) {
            return;
        }

        try {
            $this->assertQueueCanRunAsynchronously();
            $chunkSize = (int) config('smart-import.execution.chunk_size', 100);

            if ($chunkSize < 1 || $chunkSize > 1000) {
                throw new DomainException('Smart Import execution chunk size must be between 1 and 1000 rows.');
            }

            $rows = ImportRow::query()
                ->where('import_id', $sessionId)
                ->where('execution_status', 'pending');
            $total = (clone $rows)->count();
            $expectedPending = max(0, $session->execution_total - $session->processed);

            if ($total !== $expectedPending) {
                throw new DomainException('The confirmed execution row count no longer matches the staged snapshot.');
            }

            if ($total === 0) {
                $this->lifecycle->completeIfReady($sessionId, $attemptId, $confirmedRevision);

                return;
            }

            $queue = config('smart-import.queue.name');
            $connection = config('smart-import.queue.connection');

            $rows->select('id')->orderBy('id')->chunkById($chunkSize, function ($chunk) use (
                $sessionId,
                $attemptId,
                $confirmedRevision,
                $queue,
                $connection,
            ): void {
                $job = new ExecuteImportChunkJob(
                    $sessionId,
                    $attemptId,
                    $confirmedRevision,
                    $chunk->modelKeys(),
                );

                if (is_string($queue) && $queue !== '') {
                    $job->onQueue($queue);
                }

                if (is_string($connection) && $connection !== '') {
                    $job->onConnection($connection);
                }

                dispatch($job);
            });
        } catch (Throwable $exception) {
            $this->lifecycle->failDispatch($sessionId, $attemptId);
            Log::error('Smart Import chunk dispatch failed.', [
                'import_id' => $sessionId,
                'attempt' => $attemptId,
                'exception' => $exception::class,
            ]);
        }
    }
}
