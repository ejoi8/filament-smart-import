<?php

namespace SmartImport\Data;

use InvalidArgumentException;

final readonly class ImportNotificationRecipient
{
    public string $id;

    public function __construct(public string $type, string|int $id)
    {
        if ($type === '' || trim($type) !== $type || mb_strlen($type, 'UTF-8') > 191) {
            throw new InvalidArgumentException('Import notification recipient types must be non-empty and at most 191 characters.');
        }

        if ((string) $id === '' || mb_strlen((string) $id, 'UTF-8') > 191) {
            throw new InvalidArgumentException('Import notification recipient IDs must be non-empty and at most 191 characters.');
        }

        $this->id = (string) $id;
    }
}
