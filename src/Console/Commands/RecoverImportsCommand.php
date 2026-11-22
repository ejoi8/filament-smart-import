<?php

namespace SmartImport\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use SmartImport\Services\ImportRecoveryService;

final class RecoverImportsCommand extends Command
{
    protected $signature = 'smart-import:recover
        {--dry-run : Report stale sessions without changing state or dispatching jobs}
        {--limit=100 : Maximum number of stale sessions to inspect (1–500)}';

    protected $description = 'Safely recover stale Smart Import staging and execution sessions';

    public function handle(ImportRecoveryService $recovery): int
    {
        try {
            $counts = $recovery->recoverStale((int) $this->option('limit'), (bool) $this->option('dry-run'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                'Dry run inspected %d stale session(s): %d can be recovered or settled, %d need to be failed, %d skipped.',
                $counts['scanned'],
                $counts['would_recover'],
                $counts['would_fail'],
                $counts['skipped'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processed %d stale session(s): %d staging job(s) queued, %d preview job(s) queued, %d execution recovery(s) queued, %d completed, %d cancelled, %d failed, %d skipped.',
            $counts['scanned'],
            $counts['stage_requeued'],
            $counts['preview_requeued'],
            $counts['execution_requeued'],
            $counts['completed'],
            $counts['cancelled'],
            $counts['failed'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
