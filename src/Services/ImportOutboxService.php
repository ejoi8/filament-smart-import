<?php

namespace SmartImport\Services;

use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportOutboxEvent;
use SmartImport\Models\ImportSession;

/** Records terminal evidence in the caller's state transaction. */
final class ImportOutboxService
{
    public function __construct(private ImportOutboxDispatcher $dispatcher) {}

    public function recordTerminal(ImportSession $session, bool $deferred = false): ImportOutboxEvent
    {
        $status = $session->status;
        $terminalStatus = $status instanceof ImportStatus ? $status->value : (string) $status;
        $recoveryCount = (int) $session->recovery_count;
        $attemptId = $session->current_attempt > 0 ? $session->current_attempt : null;
        $eventKey = hash('sha256', implode('|', [
            'smart-import-terminal-v1',
            (string) $session->getKey(),
            (string) ($attemptId ?? 0),
            (string) $recoveryCount,
            $terminalStatus,
            (string) ($session->confirmed_revision ?? $session->staging_revision),
        ]));
        $payload = [
            'status' => $terminalStatus,
            'attempt' => $attemptId,
            'confirmed_revision' => $session->confirmed_revision,
            'processed' => (int) $session->processed,
            'created' => (int) $session->created_count,
            'updated' => (int) $session->updated_count,
            'skipped' => (int) $session->skipped_count,
            'failed' => (int) $session->failed_count,
        ];
        $event = ImportOutboxEvent::query()->where('event_key', $eventKey)->lockForUpdate()->first();

        if ($event === null) {
            $event = new ImportOutboxEvent();
            $event->forceFill([
                'import_id' => (string) $session->getKey(),
                'attempt_id' => $attemptId,
                'recovery_count' => $recoveryCount,
                'terminal_status' => $terminalStatus,
                'event_key' => $eventKey,
                'payload_version' => 1,
                'payload' => $payload,
                'delivery_status' => $deferred ? 'deferred' : 'pending',
                'available_at' => now(),
            ]);
            $event->save();
        } else {
            $event->forceFill([
                'payload' => $payload,
                'delivery_status' => $event->delivery_status === 'deferred' && ! $deferred
                    ? 'pending'
                    : $event->delivery_status,
                'available_at' => $event->delivery_status === 'deferred' && ! $deferred
                    ? now()
                    : $event->available_at,
            ]);
            $event->save();
        }

        if (! $deferred && $event->delivery_status === 'pending') {
            $eventId = (int) $event->getKey();
            $session->getConnection()->afterCommit(fn () => $this->dispatcher->dispatch($eventId));
        }

        return $event;
    }

    /** Supersede a recoverable failure before the same attempt is requeued. */
    public function supersedeDeferred(ImportSession $session): int
    {
        $query = ImportOutboxEvent::query()
            ->where('import_id', $session->getKey())
            ->where('recovery_count', $session->recovery_count)
            ->where('delivery_status', 'deferred');

        if ($session->current_attempt > 0) {
            $query->where('attempt_id', $session->current_attempt);
        } else {
            $query->whereNull('attempt_id');
        }

        return $query->update(['delivery_status' => 'superseded', 'updated_at' => now()]);
    }
}
