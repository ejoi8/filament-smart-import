<?php

namespace SmartImport\Events;

use SmartImport\Data\ImportNotificationRecipient;

/** At-least-once external delivery hook. Consumers must deduplicate with eventKey. */
final readonly class ImportOutboxAvailable
{
    /** @param array<string, int|string|null> $payload @param list<ImportNotificationRecipient> $recipients */
    public function __construct(
        public string $eventKey,
        public string $sessionId,
        public ?int $attemptId,
        public array $payload,
        public array $recipients,
    ) {}
}
