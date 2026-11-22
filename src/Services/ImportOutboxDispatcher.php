<?php

namespace SmartImport\Services;

use InvalidArgumentException;
use SmartImport\Jobs\DeliverImportOutboxJob;
use SmartImport\Models\ImportOutboxEvent;

final class ImportOutboxDispatcher
{
    public function dispatch(int $eventId): void
    {
        $queue = config('smart-import.queue.name');
        $connection = config('smart-import.queue.connection');
        $job = new DeliverImportOutboxJob($eventId);

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        dispatch($job);
    }

    /** Re-dispatch a bounded page of durable pending events after queue/worker interruptions. */
    public function dispatchPending(int $limit, bool $retryFailed = false): int
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Outbox dispatch limits must be between 1 and 500.');
        }

        if ($retryFailed) {
            ImportOutboxEvent::query()
                ->where('delivery_status', 'failed')
                ->orderBy('id')
                ->limit($limit)
                ->update([
                    'delivery_status' => 'pending',
                    'attempts' => 0,
                    'available_at' => now(),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => null,
                    'updated_at' => now(),
                ]);
        }

        $events = ImportOutboxEvent::query()
            ->where('delivery_status', 'pending')
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        foreach ($events as $event) {
            $this->dispatch((int) $event->getKey());
        }

        return $events->count();
    }
}
