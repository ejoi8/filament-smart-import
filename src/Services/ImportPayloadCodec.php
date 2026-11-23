<?php

namespace SmartImport\Services;

use DomainException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/** Versioned encryption envelope for sensitive staged cells; unwrapped arrays are legacy plaintext. */
final class ImportPayloadCodec
{
    private const MARKER = '__smart_import_payload_version';

    private const VERSION = 1;

    /** @param array<int|string, mixed> $values @param list<int|string> $sensitiveKeys @return array<int|string, mixed> */
    public function protect(array $values, array $sensitiveKeys): array
    {
        $encrypted = [];
        $plain = $values;

        foreach ($sensitiveKeys as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            try {
                $ciphertext = Crypt::encryptString(json_encode($values[$key], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                throw new DomainException('A sensitive staged value could not be encoded safely.', previous: $exception);
            }

            $encrypted[] = [
                'key_type' => is_int($key) ? 'integer' : 'string',
                'key' => (string) $key,
                'value' => $ciphertext,
            ];
            unset($plain[$key]);
        }

        if ($encrypted === []) {
            return $values;
        }

        return [
            self::MARKER => self::VERSION,
            'order' => array_map(fn (int|string $key): array => [
                'type' => is_int($key) ? 'integer' : 'string',
                'key' => (string) $key,
            ], array_keys($values)),
            'plain' => $plain,
            'encrypted' => $encrypted,
        ];
    }

    /** @param array<int|string, mixed>|null $stored @return array<int|string, mixed> */
    public function reveal(?array $stored, ?array $onlyKeys = null): array
    {
        if ($stored === null) {
            return [];
        }

        if (! array_key_exists(self::MARKER, $stored)
            || ! array_key_exists('order', $stored)
            || ! array_key_exists('plain', $stored)
            || ! array_key_exists('encrypted', $stored)) {
            return $onlyKeys === null ? $stored : array_intersect_key($stored, array_fill_keys($onlyKeys, true));
        }

        if (($stored[self::MARKER] ?? null) !== self::VERSION
            || ! is_array($stored['order'] ?? null)
            || ! is_array($stored['plain'] ?? null)
            || ! is_array($stored['encrypted'] ?? null)) {
            throw new DomainException('The encrypted staged values use an unsupported payload version.');
        }

        $values = $onlyKeys === null
            ? $stored['plain']
            : array_filter(
                $stored['plain'],
                static fn (mixed $value, int|string $key): bool => in_array($key, $onlyKeys, true),
                ARRAY_FILTER_USE_BOTH,
            );

        foreach ($stored['encrypted'] as $entry) {
            if (! is_array($entry)
                || ! in_array($entry['key_type'] ?? null, ['integer', 'string'], true)
                || ! is_string($entry['key'] ?? null)
                || ! is_string($entry['value'] ?? null)) {
                throw new DomainException('The encrypted staged values are incomplete.');
            }

            $key = $entry['key_type'] === 'integer' ? (int) $entry['key'] : $entry['key'];

            if ($onlyKeys !== null && ! in_array($key, $onlyKeys, true)) {
                continue;
            }

            try {
                $decoded = json_decode(Crypt::decryptString($entry['value']), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $exception) {
                throw new DomainException('A sensitive staged value could not be decrypted.', previous: $exception);
            }

            $values[$key] = $decoded;
        }

        $ordered = [];

        foreach ($stored['order'] as $entry) {
            if (! is_array($entry)
                || ! in_array($entry['type'] ?? null, ['integer', 'string'], true)
                || ! is_string($entry['key'] ?? null)) {
                throw new DomainException('The encrypted staged values have an invalid field order.');
            }

            $key = $entry['type'] === 'integer' ? (int) $entry['key'] : $entry['key'];

            if ($onlyKeys !== null && ! in_array($key, $onlyKeys, true)) {
                continue;
            }

            if (array_key_exists($key, $values)) {
                $ordered[$key] = $values[$key];
            }
        }

        foreach ($values as $key => $value) {
            if ($onlyKeys !== null && ! in_array($key, $onlyKeys, true)) {
                continue;
            }

            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }

    /** @param array<int|string, mixed>|null $stored @return list<int|string> */
    public function keys(?array $stored): array
    {
        if ($stored === null) {
            return [];
        }

        if (! array_key_exists(self::MARKER, $stored)
            || ! array_key_exists('order', $stored)
            || ! array_key_exists('plain', $stored)
            || ! array_key_exists('encrypted', $stored)) {
            return array_keys($stored);
        }

        if (($stored[self::MARKER] ?? null) !== self::VERSION
            || ! is_array($stored['order'] ?? null)
            || ! is_array($stored['plain'] ?? null)
            || ! is_array($stored['encrypted'] ?? null)) {
            throw new DomainException('The encrypted staged values use an unsupported payload version.');
        }

        $available = [];

        foreach (array_keys($stored['plain']) as $key) {
            $available[$key] = true;
        }

        foreach ($stored['encrypted'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['key'] ?? null)) {
                continue;
            }

            $key = ($entry['key_type'] ?? null) === 'integer' ? (int) $entry['key'] : $entry['key'];
            $available[$key] = true;
        }

        $ordered = [];

        foreach ($stored['order'] as $entry) {
            if (! is_array($entry)
                || ! in_array($entry['type'] ?? null, ['integer', 'string'], true)
                || ! is_string($entry['key'] ?? null)) {
                throw new DomainException('The encrypted staged values have an invalid field order.');
            }

            $key = $entry['type'] === 'integer' ? (int) $entry['key'] : $entry['key'];

            if (array_key_exists($key, $available)) {
                $ordered[$key] = true;
            }
        }

        foreach ($available as $key => $_available) {
            $ordered[$key] = true;
        }

        return array_keys($ordered);
    }
}
