<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportActorIdentity;
use SmartImport\Models\ImportPreset;

final class MappingPresetService
{
    private const MAX_OPTIONS_BYTES = 16384;

    public function __construct(
        private ImportAuthorization $authorization,
        private ImporterRegistry $importers,
        private ColumnMapper $columnMapper,
    ) {}

    /**
     * Save or replace an actor/scope-owned preset for one sheet/header signature.
     *
     * @param array<int, string|int|float|bool|null> $headers Raw header values keyed by one-based source column.
     * @param array<int, string|null> $mapping Complete source column to allowed field/null ignore mapping.
     * @param array<mixed> $options Non-secret scalar mapping options.
     */
    public function save(
        string $importerKey,
        string $name,
        int $sheetIndex,
        string $sheetName,
        ?int $headerRow,
        array $headers,
        array $mapping,
        Authenticatable $actor,
        array $options = [],
    ): ImportPreset {
        $definition = $this->definitionFor($importerKey, $actor);
        $this->validateName($name);
        $this->validateSheetContext($sheetIndex, $sheetName, $headerRow);

        $headers = $this->columnMapper->validateHeaders($headers);
        $validatedMapping = $this->columnMapper->validateManualMapping($definition, $headers, $mapping);
        $storedMapping = $validatedMapping['mapping'];

        foreach ($validatedMapping['ignored_columns'] as $sourceColumn) {
            $storedMapping[$sourceColumn] = null;
        }

        ksort($storedMapping, SORT_NUMERIC);

        $safeOptions = $this->validateOptions($options);
        $fingerprint = $this->sourceFingerprint($sheetIndex, $sheetName, $headerRow, $headers);
        $scopeKey = $this->authorization->presetScopeKey($actor);
        $identity = ImportActorIdentity::from($actor);

        return DB::transaction(function () use (
            $importerKey,
            $definition,
            $name,
            $storedMapping,
            $safeOptions,
            $fingerprint,
            $scopeKey,
            $identity,
            $actor,
        ): ImportPreset {
            $preset = $this->authorization
                ->applyPresetScope(
                    ImportPreset::query()
                        ->where('importer_key', $importerKey)
                        ->where('name', $name)
                        ->lockForUpdate(),
                    $actor,
                )
                ->first();

            if ($preset instanceof ImportPreset) {
                $this->authorization->authorize('update', $actor, $preset);
            } else {
                $this->authorization->authorize('create', $actor, ImportPreset::class);
                $preset = new ImportPreset();
                $preset->forceFill([
                    'created_by_type' => $identity->type,
                    'created_by_id' => $identity->id,
                ]);
            }

            $preset->forceFill([
                'importer_key' => $importerKey,
                'importer_version' => $definition->version(),
                'scope_key' => $scopeKey,
                'name' => $name,
                'source_fingerprint' => $fingerprint,
                'mapping' => $storedMapping,
                'options' => $safeOptions === [] ? null : $safeOptions,
            ]);
            $preset->save();

            return $preset;
        });
    }

    /**
     * List metadata only; callers must use resolve() to obtain a compatible mapping.
     *
     * @return Collection<int, ImportPreset>
     */
    public function forImporter(string $importerKey, Authenticatable $actor): Collection
    {
        $this->definitionFor($importerKey, $actor);

        return $this->authorization
            ->scopePresets(ImportPreset::query(), $actor)
            ->where('importer_key', $importerKey)
            ->orderBy('name')
            ->get(['id', 'name', 'importer_key', 'importer_version', 'source_fingerprint', 'created_at', 'updated_at']);
    }

    /**
     * Return a mapping only when both importer version and sheet/header fingerprint still match.
     * Incompatible presets return a warning reason and no mapping/options.
     *
     * @param array<int, string|int|float|bool|null> $headers
     * @return array{preset_id: string, name: string, importer_version: string, compatible: bool, incompatibility: ?string, mapping: ?array, options: ?array}
     */
    public function resolve(
        string $presetId,
        string $importerKey,
        int $sheetIndex,
        string $sheetName,
        ?int $headerRow,
        array $headers,
        Authenticatable $actor,
    ): array {
        $definition = $this->definitionFor($importerKey, $actor);
        $this->validateSheetContext($sheetIndex, $sheetName, $headerRow);
        $headers = $this->columnMapper->validateHeaders($headers);
        $fingerprint = $this->sourceFingerprint($sheetIndex, $sheetName, $headerRow, $headers);

        $preset = $this->authorization
            ->scopePresets(ImportPreset::query(), $actor)
            ->where('importer_key', $importerKey)
            ->whereKey($presetId)
            ->firstOrFail();

        $this->authorization->authorize('view', $actor, $preset);

        $incompatibility = match (true) {
            $preset->importer_version !== $definition->version() => 'importer_version',
            ! hash_equals($preset->source_fingerprint, $fingerprint) => 'source_fingerprint',
            default => null,
        };

        if ($incompatibility === null) {
            if (! is_array($preset->mapping)) {
                $incompatibility = 'mapping_invalid';
            } else {
                try {
                    $this->columnMapper->validateManualMapping($definition, $headers, $preset->mapping);
                } catch (InvalidArgumentException) {
                    $incompatibility = 'mapping_invalid';
                }
            }
        }

        if ($incompatibility === null) {
            try {
                $this->validateOptions($preset->options ?? []);
            } catch (InvalidArgumentException) {
                $incompatibility = 'options_invalid';
            }
        }

        return [
            'preset_id' => (string) $preset->getKey(),
            'name' => $preset->name,
            'importer_version' => $preset->importer_version,
            'compatible' => $incompatibility === null,
            'incompatibility' => $incompatibility,
            'mapping' => $incompatibility === null ? $preset->mapping : null,
            'options' => $incompatibility === null ? $preset->options : null,
        ];
    }

    private function definitionFor(string $importerKey, Authenticatable $actor): ImportDefinition
    {
        $definition = $this->importers->get($importerKey);
        $this->authorization->authorize('useImporter', $actor, $definition);

        return $definition;
    }

    private function validateName(string $name): void
    {
        if ($name === '' || trim($name) !== $name || ! mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 120) {
            throw new InvalidArgumentException('Mapping preset names must be trimmed, non-empty UTF-8 strings of at most 120 characters.');
        }
    }

    private function validateSheetContext(int $sheetIndex, string $sheetName, ?int $headerRow): void
    {
        if ($sheetIndex < 0 || $sheetIndex > 65535) {
            throw new InvalidArgumentException('A mapping preset sheet index must be between 0 and 65535.');
        }

        if ($sheetName === '' || ! mb_check_encoding($sheetName, 'UTF-8') || mb_strlen($sheetName, 'UTF-8') > 31) {
            throw new InvalidArgumentException('A mapping preset sheet name must be valid UTF-8 and at most 31 characters.');
        }

        if ($headerRow !== null && $headerRow < 1) {
            throw new InvalidArgumentException('A mapping preset header row must be a positive, one-based row number.');
        }
    }

    /** @param array<mixed> $options @return array<mixed> */
    private function validateOptions(array $options): array
    {
        $this->validateOptionValues($options);

        try {
            $encoded = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Mapping preset options must contain JSON-safe UTF-8 values.', 0, $exception);
        }

        if (strlen($encoded) > self::MAX_OPTIONS_BYTES) {
            throw new InvalidArgumentException(sprintf('Mapping preset options cannot exceed %d encoded bytes.', self::MAX_OPTIONS_BYTES));
        }

        return $options;
    }

    /** @param array<mixed> $options */
    private function validateOptionValues(array $options, int $depth = 0): void
    {
        if ($depth > 8) {
            throw new InvalidArgumentException('Mapping preset options cannot be nested more than 8 levels.');
        }

        foreach ($options as $key => $value) {
            if (! is_int($key) && ! is_string($key)) {
                throw new InvalidArgumentException('Mapping preset option keys must be strings or integers.');
            }

            if (is_string($key) && preg_match('/password|secret|token|credential|authorization|api[_-]?key/i', $key) === 1) {
                throw new InvalidArgumentException(sprintf('Mapping preset option [%s] looks sensitive and cannot be stored.', $key));
            }

            if (is_array($value)) {
                $this->validateOptionValues($value, $depth + 1);

                continue;
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Mapping preset options may contain only scalar, null, or nested array values.');
            }

            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException('Mapping preset options cannot contain non-finite numbers.');
            }
        }
    }

    /**
     * @param array<int, string|int|float|bool|null> $headers
     */
    private function sourceFingerprint(int $sheetIndex, string $sheetName, ?int $headerRow, array $headers): string
    {
        $columns = [];

        foreach ($headers as $sourceColumn => $header) {
            $columns[] = [
                'source_column' => $sourceColumn,
                'type' => get_debug_type($header),
                'value' => is_string($header) ? base64_encode($header) : $header,
            ];
        }

        try {
            $payload = json_encode([
                'signature_version' => 1,
                'sheet_index' => $sheetIndex,
                'sheet_name' => $sheetName,
                'header_row' => $headerRow,
                'columns' => $columns,
            ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The mapping preset source signature could not be encoded safely.', 0, $exception);
        }

        return hash('sha256', $payload);
    }
}
