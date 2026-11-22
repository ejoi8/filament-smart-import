<?php

namespace SmartImport\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use SmartImport\Services\ImportRetentionService;

final class RetentionDryRunCommand extends Command
{
    protected $signature = 'smart-import:retention-dry-run {--older-than=365} {--limit=100} {--cursor=}';

    protected $description = 'Report old Smart Import files and rows that could be considered for retention.';

    public function handle(ImportRetentionService $retention): int
    {
        $olderThan = filter_var($this->option('older-than'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($olderThan) || ! is_int($limit)) {
            $this->error('Retention age and limit must be integers.');

            return self::INVALID;
        }

        try {
            $cursor = $this->option('cursor');

            if ($cursor !== null && ! is_string($cursor)) {
                throw new InvalidArgumentException('The retention cursor must be a string.');
            }

            $report = $retention->inspect($olderThan, $limit, $cursor);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
