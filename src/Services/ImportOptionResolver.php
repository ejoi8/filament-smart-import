<?php

namespace SmartImport\Services;

use InvalidArgumentException;
use JsonException;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportOptionProvider;
use SmartImport\Data\ImportOption;

final class ImportOptionResolver
{
    private const MAX_OPTIONS_BYTES = 8192;

    /** Validate importer-owned choices and apply defaults without accepting request-defined values. */
    public function resolve(ImportDefinition $definition, array $requested): array
    {
        $definitions = $definition instanceof ImportOptionProvider ? $definition->importOptions() : [];

        if (! array_is_list($definitions)) {
            throw new InvalidArgumentException('Importer option definitions must be provided as a list.');
        }

        $allowed = [];

        foreach ($definitions as $option) {
            if (! $option instanceof ImportOption || array_key_exists($option->key, $allowed)) {
                throw new InvalidArgumentException('Importer options must be valid, uniquely named ImportOption definitions.');
            }

            $allowed[$option->key] = $option;
        }

        if (! is_array($requested) || array_diff(array_keys($requested), array_keys($allowed)) !== []) {
            throw new InvalidArgumentException('An importer option was not declared by this importer.');
        }

        $resolved = [];

        foreach ($allowed as $key => $option) {
            $value = array_key_exists($key, $requested) ? $requested[$key] : $option->default;

            if (! is_string($value) || ! array_key_exists($value, $option->choices)) {
                throw new InvalidArgumentException(sprintf('The selected value for importer option [%s] is not allowed.', $key));
            }

            $resolved[$key] = $value;
        }

        try {
            $encoded = json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Importer options must contain JSON-safe UTF-8 values.', 0, $exception);
        }

        if (strlen($encoded) > self::MAX_OPTIONS_BYTES) {
            throw new InvalidArgumentException(sprintf('Importer options cannot exceed %d encoded bytes.', self::MAX_OPTIONS_BYTES));
        }

        return $resolved;
    }
}
