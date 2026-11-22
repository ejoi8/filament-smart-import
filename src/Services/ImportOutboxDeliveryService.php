<?php

namespace SmartImport\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SmartImport\Contracts\ImportExecutionContext;
use SmartImport\Contracts\ImportNotificationRecipientResolver;
use SmartImport\Data\ImportNotificationRecipient;
use SmartImport\Events\ImportOutboxAvailable;
use SmartImport\Models\ImportNotification;
use SmartImport\Models\ImportOutboxEvent;
use SmartImport\Models\ImportSession;
use Throwable;

final class ImportOutboxDeliveryService
{
    public function __construct(
        private ImportNotificationRecipientResolver $recipients,
        private ImportExecutionContext $executionContext,
        private ImportAuditService $audit,
    ) {}

    public function deliver(int $eventId): string
    {
        $claim = $this->claim($eventId);

        if ($claim === null) {
            return 'skipped';
        }

        [$event, $leaseToken] = $claim;

        try {
            $session = ImportSession::query()->findOrFail($event->import_id);
            $recipients = $this->executionContext->run($session, function () use ($session): array {
                $resolved = $this->recipients->recipients($session);
                $unique = [];

                foreach ($resolved as $recipient) {
                    if (! $recipient instanceof ImportNotificationRecipient) {
                        continue;
                    }

                    $unique[$recipient->type."\0".$recipient->id] = $recipient;
                }

                return array_values($unique);
            });
            $data = $this->notificationData($event);

            foreach ($recipients as $recipient) {
                DB::table('smart_import_notifications')->insertOrIgnore([
                    'outbox_id' => $event->getKey(),
                    'notifiable_type' => $recipient->type,
                    'notifiable_id' => $recipient->id,
                    'channel' => 'database',
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->executionContext->run($session, fn () => event(new ImportOutboxAvailable(
                (string) $event->event_key,
                (string) $event->import_id,
                $event->attempt_id,
                $event->payload ?? [],
                $recipients,
            )));

            $delivered = DB::transaction(function () use ($eventId, $leaseToken): bool {
                $current = ImportOutboxEvent::query()->lockForUpdate()->find($eventId);

                if ($current === null || $current->delivery_status !== 'leased' || $current->lease_token !== $leaseToken) {
                    return false;
                }

                $current->forceFill([
                    'delivery_status' => 'delivered',
                    'delivered_at' => now(),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => null,
                ]);
                $current->save();

                return true;
            }, 3);

            return $delivered ? 'delivered' : 'lease_lost';
        } catch (Throwable $exception) {
            $this->reschedule($eventId, $leaseToken);
            Log::warning('Smart Import outbox delivery failed.', [
                'import_id' => $event->import_id,
                'event_key' => $event->event_key,
                'attempt' => $event->attempts,
                'exception' => $exception::class,
            ]);

            return 'failed';
        }
    }

    private function claim(int $eventId): ?array
    {
        return DB::transaction(function () use ($eventId): ?array {
            $event = ImportOutboxEvent::query()->lockForUpdate()->find($eventId);

            if ($event === null
                || ! in_array($event->delivery_status, ['pending', 'leased'], true)
                || $event->available_at->isFuture()
                || ($event->delivery_status === 'leased' && $event->lease_expires_at?->isFuture())) {
                return null;
            }

            $leaseSeconds = (int) config('smart-import.notifications.lease_seconds', 300);

            if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
                throw new \InvalidArgumentException('Smart Import outbox lease seconds must be between 30 and 3600.');
            }

            $leaseToken = (string) Str::uuid();
            $event->forceFill([
                'delivery_status' => 'leased',
                'attempts' => $event->attempts + 1,
                'lease_token' => $leaseToken,
                'lease_expires_at' => now()->addSeconds($leaseSeconds),
            ]);
            $event->save();

            return [$event->fresh(), $leaseToken];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function notificationData(ImportOutboxEvent $event): array
    {
        $status = $event->terminal_status;

        return [
            'event_key' => (string) $event->event_key,
            'import_id' => (string) $event->import_id,
            'attempt' => $event->attempt_id,
            'status' => $status,
            'title' => match ($status) {
                'completed' => 'Import completed',
                'cancelled' => 'Import cancelled',
                default => 'Import failed',
            },
            'message' => match ($status) {
                'completed' => 'The import finished. Review its authorized history for row outcomes.',
                'cancelled' => 'The import was cancelled. Review its authorized history for committed outcomes.',
                default => 'The import failed. Review its authorized history for details.',
            },
            'counts' => [
                'processed' => (int) ($event->payload['processed'] ?? 0),
                'created' => (int) ($event->payload['created'] ?? 0),
                'updated' => (int) ($event->payload['updated'] ?? 0),
                'skipped' => (int) ($event->payload['skipped'] ?? 0),
                'failed' => (int) ($event->payload['failed'] ?? 0),
            ],
        ];
    }

    private function reschedule(int $eventId, string $leaseToken): void
    {
        DB::transaction(function () use ($eventId, $leaseToken): void {
            $event = ImportOutboxEvent::query()->lockForUpdate()->find($eventId);

            if ($event === null || $event->delivery_status !== 'leased' || $event->lease_token !== $leaseToken) {
                return;
            }

            $maxAttempts = (int) config('smart-import.notifications.max_attempts', 8);
            $backoff = config('smart-import.notifications.backoff_seconds', [30, 120, 300]);

            if ($maxAttempts < 1 || $maxAttempts > 20
                || ! is_array($backoff)
                || ! array_is_list($backoff)
                || count($backoff) < 1
                || count($backoff) > 8
                || array_filter($backoff, static fn (mixed $delay): bool => ! is_int($delay) || $delay < 0 || $delay > 86400) !== []) {
                throw new \InvalidArgumentException('Smart Import outbox retry settings are invalid.');
            }

            $exhausted = $event->attempts >= $maxAttempts;
            $delay = $backoff[min(max(0, $event->attempts - 1), count($backoff) - 1)];
            $event->forceFill([
                'delivery_status' => $exhausted ? 'failed' : 'pending',
                'available_at' => now()->addSeconds($delay),
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error_code' => 'delivery_failed',
            ]);
            $event->save();

            $session = ImportSession::query()->find($event->import_id);

            if ($session !== null) {
                $this->audit->record($session, 'notification.delivery_failed', reasonCode: 'delivery_failed', metadata: [
                    'outbox_id' => (int) $event->getKey(),
                    'delivery_attempt' => (int) $event->attempts,
                ]);
            }
        }, 3);
    }
}
