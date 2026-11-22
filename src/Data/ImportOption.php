<?php

namespace SmartImport\Data;

use InvalidArgumentException;

final readonly class ImportOption
{
    /** @param array<string, string> $choices Stable string value => display label. */
    public function __construct(
        public string $key,
        public string $label,
        public array $choices,
        public string $default,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1
            || preg_match('/password|secret|token|credential|authorization|api[_-]?key/i', $key) === 1) {
            throw new InvalidArgumentException('Importer option keys must be safe, non-sensitive identifiers.');
        }

        if (trim($label) === '' || ! mb_check_encoding($label, 'UTF-8') || mb_strlen($label, 'UTF-8') > 120) {
            throw new InvalidArgumentException('Importer option labels must be non-empty UTF-8 text of at most 120 characters.');
        }

        if ($choices === [] || count($choices) > 100) {
            throw new InvalidArgumentException('Importer options must declare between one and 100 allowed choices.');
        }

        foreach ($choices as $value => $choiceLabel) {
            $value = is_int($value) ? (string) $value : $value;

            if (! is_string($value) || $value === '' || strlen($value) > 255
                || ! is_string($choiceLabel) || trim($choiceLabel) === ''
                || ! mb_check_encoding($value, 'UTF-8') || ! mb_check_encoding($choiceLabel, 'UTF-8')) {
                throw new InvalidArgumentException('Importer option choices must use non-empty UTF-8 string values and labels.');
            }
        }

        if (! array_key_exists($default, $choices)) {
            throw new InvalidArgumentException('An importer option default must be one of its declared choices.');
        }
    }
}
