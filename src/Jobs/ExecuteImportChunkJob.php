<?php

namespace SmartImport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SmartImport\Exceptions\ImportCancellationRequested;
use SmartImport\Contracts\ImportExecutionContext;
use SmartImport\Models\ImportSession;
use SmartImport\Services\ImportExecutionLifecycle;
use SmartImport\Services\ImportExecutionService;
use Throwable;

final class ExecuteImportChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    /** @param list<int> $rowIds */
    public function __construct(
        public string $sessionId,
        public int $attemptId,
        public int $confirmedRevision,
        public array $rowIds,
    ) {
        if ($rowIds === [] || ! array_is_list($rowIds) || count($rowIds) > 1000) {
            throw new \InvalidArgumentException('Execution chunk jobs require 1 to 1000 row IDs.');
        }

        foreach ($rowIds as $rowId) {
            if (! is_int($rowId) || $rowId < 1) {
                throw new \InvalidArgumentException('Execution chunk row IDs must be positive integers.');
            }
        }

        $tries = (int) config('smart-import.execution.job_tries', 3);

        if ($tries < 1 || $tries > 10) {
            throw new \InvalidArgumentException('Smart Import execution job tries must be between 1 and 10.');
        }

        $backoff = config('smart-import.execution.job_backoff_seconds', [10, 30, 60]);

        if (! is_array($backoff)
            || ! array_is_list($backoff)
            || count($backoff) > 8
            || array_filter($backoff, static fn (mixed $seconds): bool => ! is_int($seconds) || $seconds < 0 || $seconds > 86400) !== []) {
            throw new \InvalidArgumentException('Smart Import execution backoff must be a list of 0 to 86400 second delays, with no more than eight entries.');
        }

        $this->tries = $tries;
        $this->backoff = $backoff;
    }

    public function handle(
        ImportExecutionService $execution,
        ImportExecutionLifecycle $lifecycle,
        ?ImportExecutionContext $context = null,
    ): void
    {
        $session = ImportSession::query()->find($this->sessionId);

        if ($session === null) {
            return;
        }

        ($context ?? app(ImportExecutionContext::class))->run($session, fn () => $this->runChunk($execution, $lifecycle));
    }

    private function runChunk(ImportExecutionService $execution, ImportExecutionLifecycle $lifecycle): void
    {
        if (! $lifecycle->beginChunk($this->sessionId, $this->attemptId, $this->confirmedRevision)) {
            return;
        }

        foreach ($this->rowIds as $rowId) {
            try {
                $execution->processRow($this->sessionId, $rowId, $this->confirmedRevision, $this->attemptId);
            } catch (ImportCancellationRequested) {
                $lifecycle->completeIfReady($this->sessionId, $this->attemptId, $this->confirmedRevision);

                return;
            }

            $lifecycle->completeIfReady($this->sessionId, $this->attemptId, $this->confirmedRevision);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ImportExecutionLifecycle::class)->failChunk(
            $this->sessionId,
            $this->attemptId,
            $this->confirmedRevision,
        );
    }
}
