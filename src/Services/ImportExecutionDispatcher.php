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

        if ($driver === 'sync' && ! app()->environment('testing')) {
            throw new DomainException('Smart Import requires an asynchronous Laravel queue connection; the sync driver would block the request.');
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
