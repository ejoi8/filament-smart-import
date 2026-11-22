<?php

namespace SmartImport\Console\Commands;

use Illuminate\Console\Command;
use SmartImport\Models\ImportOutboxEvent;
use SmartImport\Services\ImportOutboxDispatcher;

final class DeliverImportOutboxCommand extends Command
{
    protected $signature = 'smart-import:deliver-outbox {--limit=100} {--retry-failed}';

    protected $description = 'Dispatch pending Smart Import terminal notifications to the configured queue.';

    public function handle(ImportOutboxDispatcher $dispatcher): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 500) {
            $this->error('The outbox dispatch limit must be between 1 and 500.');

            return self::INVALID;
        }

        $dispatched = $dispatcher->dispatchPending($limit, (bool) $this->option('retry-failed'));
        $deferred = ImportOutboxEvent::query()->where('delivery_status', 'deferred')->count();
        $failed = ImportOutboxEvent::query()->where('delivery_status', 'failed')->count();

        $this->line(json_encode([
            'dispatched' => $dispatched,
            'deferred' => $deferred,
            'failed' => $failed,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
