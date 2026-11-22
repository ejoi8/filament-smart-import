<?php

namespace SmartImport\Services;

use DomainException;

/** Versioned signatures for indexed, low-entropy operational identifiers. */
final class ImportIndexHasher
{
    public const CURRENT_VERSION = 2;

    public function hash(string $value, int $version): string
    {
        if ($version === 1) {
            return hash('sha256', $value);
        }

        if ($version !== self::CURRENT_VERSION) {
            throw new DomainException('The Smart Import index signature version is not supported.');
        }

        $key = config('smart-import.security.index_key') ?: config('app.key');

        if (! is_string($key) || strlen($key) < 32) {
            throw new DomainException('Smart Import requires a stable index key of at least 32 characters.');
        }

        return hash_hmac('sha256', $value, $key);
    }
}
