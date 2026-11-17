<?php

namespace SmartImport\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use InvalidArgumentException;
use SmartImport\Contracts\FieldTransformer;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Enums\BlankCellPolicy;
use Throwable;

final class RowDataProcessor
{
    public function __construct(
        private ValidationFactory $validatorFactory,
        private RelationshipLookupResolver $relationshipLookups,
    ) {}

    /**
     * Apply a source-indexed mapping, run field transformers in definition order, and validate with Laravel.
     *
     * @param array<int, string|int|float|bool|null> $sourceValues One-based source column indexes to raw cell values.
     * @param array<int, string|null> $mapping One-based source column indexes to allowed field names; null means ignore.
     * @return array{
     *     mapped_values: array<string, string|int|float|bool|null>,
     *     normalized_values: array<string, mixed>,
     *     errors: list<array{field: string, code: string, safe_message: string, source_row: int}>,
     *     valid: bool
     * }
     */
    public function process(ImportDefinition $definition, array $sourceValues, array $mapping, int $sourceRow): array
    {
        return $this->processBatch($definition, [[
            'values' => $sourceValues,
            'row_number' => $sourceRow,
        ]], $mapping)[0];
    }

    /**
     * Map and validate a bounded source batch, resolving each configured relationship in batched queries.
     *
     * @param list<array{values: array<int, string|int|float|bool|null>, row_number: int}> $sourceRows
     * @param array<int, string|null> $mapping
     * @return list<array{
     *     mapped_values: array<string, string|int|float|bool|null>,
     *     normalized_values: array<string, mixed>,
     *     errors: list<array{field: string, code: string, safe_message: string, source_row: int}>,
     *     valid: bool
     * }>
     */
    public function processBatch(ImportDefinition $definition, array $sourceRows, array $mapping): array
    {
        if (! array_is_list($sourceRows)) {
            throw new InvalidArgumentException('Source rows must be provided as a list.');
        }

        $fields = $this->fieldsByName($definition);
        $sourceByField = [];

        foreach ($mapping as $sourceColumn => $fieldName) {
            if (! is_int($sourceColumn) || $sourceColumn < 1) {
                throw new InvalidArgumentException('Mappings must use positive, one-based source column indexes.');
            }

            if ($fieldName === null) {
                continue;
            }

            if (! is_string($fieldName) || ! array_key_exists($fieldName, $fields)) {
                throw new InvalidArgumentException(sprintf(
                    'Mapping for source column [%d] must select an allowed field or null to ignore it.',
                    $sourceColumn,
                ));
            }

            if (array_key_exists($fieldName, $sourceByField)) {
                throw new InvalidArgumentException(sprintf(
                    'Importer field [%s] is mapped from more than one source column.',
                    $fieldName,
                ));
            }

            $sourceByField[$fieldName] = $sourceColumn;
        }

        $states = [];

        foreach ($sourceRows as $sourceRow) {
            $sourceValues = $sourceRow['values'] ?? null;
            $rowNumber = $sourceRow['row_number'] ?? null;

            if (! is_array($sourceValues) || ! is_int($rowNumber) || $rowNumber < 1) {
                throw new InvalidArgumentException('Each source row must include values and a positive one-based row number.');
            }

            foreach ($sourceValues as $sourceColumn => $value) {
                if (! is_int($sourceColumn) || $sourceColumn < 1) {
                    throw new InvalidArgumentException('Source values must use positive, one-based column indexes.');
                }

                if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null) {
                    throw new InvalidArgumentException(sprintf(
                        'Source cell values must be scalar or null; column [%d] contains [%s].',
                        $sourceColumn,
                        get_debug_type($value),
                    ));
                }
            }

            $mappedValues = [];

            foreach ($fields as $fieldName => $_field) {
                if (! array_key_exists($fieldName, $sourceByField)) {
                    continue;
                }

                $sourceColumn = $sourceByField[$fieldName];

                if (array_key_exists($sourceColumn, $sourceValues)) {
                    $mappedValues[$fieldName] = $sourceValues[$sourceColumn];
                }
            }

            $normalizedValues = [];
            $errors = [];
            $invalidFields = [];

            foreach ($fields as $fieldName => $field) {
                if (! array_key_exists($fieldName, $mappedValues)) {
                    continue;
                }

                $value = $mappedValues[$fieldName];

                if (is_string($value) && in_array($value, $field->nullMarkers, true)) {
                    $normalizedValues[$fieldName] = null;

                    continue;
                }

                if ($this->isBlankCellValue($value)) {
                    match ($field->blankCellPolicy) {
                        BlankCellPolicy::Preserve => null,
                        BlankCellPolicy::SetEmptyString => $normalizedValues[$fieldName] = '',
                        BlankCellPolicy::SetNull => $normalizedValues[$fieldName] = null,
                    };

                    continue;
                }

                try {
                    foreach ($field->transformers as $transformer) {
                        $value = is_string($transformer)
                            ? $this->applyBuiltInTransformer($transformer, $value)
                            : $this->applyCustomTransformer($transformer, $value);
                    }

                    $normalizedValues[$fieldName] = $value;
                } catch (Throwable) {
                    unset($normalizedValues[$fieldName]);
                    $invalidFields[$fieldName] = true;
                    $errors[] = [
                        'field' => $fieldName,
                        'code' => 'transform_failed',
                        'safe_message' => 'The value could not be transformed for this field.',
                        'source_row' => $rowNumber,
                    ];
                }
            }

            $states[] = [
                'row_number' => $rowNumber,
                'mapped_values' => $mappedValues,
                'normalized_values' => $normalizedValues,
                'errors' => $errors,
                'invalid_fields' => $invalidFields,
            ];
        }

        foreach ($fields as $fieldName => $field) {
            if ($field->relationshipLookup === null) {
                continue;
            }

            $stateIndexes = [];
            $lookupValues = [];

            foreach ($states as $index => $state) {
                if (! isset($state['invalid_fields'][$fieldName])
                    && array_key_exists($fieldName, $state['normalized_values'])) {
                    $stateIndexes[] = $index;
                    $lookupValues[] = $state['normalized_values'][$fieldName];
                }
            }

            if ($lookupValues === []) {
                continue;
            }

            $resolutions = $this->relationshipLookups->resolveBatch($definition, $field, $lookupValues);

            foreach ($resolutions as $index => $resolution) {
                $stateIndex = $stateIndexes[$index];

                if ($resolution['error'] !== null) {
                    unset($states[$stateIndex]['normalized_values'][$fieldName]);
                    $states[$stateIndex]['invalid_fields'][$fieldName] = true;
                    $states[$stateIndex]['errors'][] = [
                        'field' => $fieldName,
                        'code' => $resolution['error']['code'],
                        'safe_message' => $resolution['error']['safe_message'],
                        'source_row' => $states[$stateIndex]['row_number'],
                    ];
                } else {
                    $states[$stateIndex]['normalized_values'][$fieldName] = $resolution['value'];
                }
            }
        }

        $results = [];

        foreach ($states as $state) {
            $rules = [];
            $attributes = [];

            foreach ($fields as $fieldName => $field) {
                if (isset($state['invalid_fields'][$fieldName])) {
                    continue;
                }

                $fieldRules = $field->rules;

                if ($field->required) {
                    array_unshift($fieldRules, 'required');
                }

                if ($fieldRules !== []) {
                    $rules[$fieldName] = $fieldRules;
                    $attributes[$fieldName] = $field->label;
                }
            }

            if ($rules !== []) {
                $validator = $this->validatorFactory->make($state['normalized_values'], $rules, [], $attributes);

                if ($validator->fails()) {
                    $failedRules = $validator->failed();

                    foreach ($validator->errors()->messages() as $fieldName => $messages) {
                        $ruleNames = array_keys($failedRules[$fieldName] ?? []);

                        foreach ($messages as $index => $message) {
                            $state['errors'][] = [
                                'field' => $fieldName,
                                'code' => $this->validationCode($ruleNames[$index] ?? 'validation'),
                                'safe_message' => $message,
                                'source_row' => $state['row_number'],
                            ];
                        }
                    }
                }
            }

            $results[] = [
                'mapped_values' => $state['mapped_values'],
                'normalized_values' => $state['normalized_values'],
                'errors' => $state['errors'],
                'valid' => $state['errors'] === [],
            ];
        }

        return $results;
    }

    /** @return array<string, ImportField> */
    private function fieldsByName(ImportDefinition $definition): array
    {
        $fields = [];

        foreach ($definition->fields() as $field) {
            if (! $field instanceof ImportField || array_key_exists($field->name, $fields)) {
                throw new InvalidArgumentException(sprintf('Importer [%s] has invalid or duplicate field metadata.', $definition->key()));
            }

            $fields[$field->name] = $field;
        }

        if ($fields === []) {
            throw new InvalidArgumentException(sprintf('Importer [%s] must define at least one field.', $definition->key()));
        }

        return $fields;
    }

    private function applyCustomTransformer(mixed $transformer, mixed $value): mixed
    {
        if (! $transformer instanceof FieldTransformer) {
            throw new InvalidArgumentException('Configured custom field transformers must implement the package transformer contract.');
        }

        return $transformer->transform($value);
    }

    private function isBlankCellValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function applyBuiltInTransformer(string $transformer, mixed $value): mixed
    {
        return match ($transformer) {
            'trim' => is_string($value) ? trim($value) : $value,
            'string' => $this->toString($value),
            'date' => $this->toIsoDate($value),
            'number' => $this->toCanonicalNumber($value),
            default => throw new InvalidArgumentException('Unknown built-in field transformer.'),
        };
    }

    private function toString(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value) && is_finite($value)) {
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }

        throw new InvalidArgumentException('Only scalar values can be converted to strings.');
    }

    private function toIsoDate(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Date values must use the YYYY-MM-DD format.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $dateErrors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException('Date values must be valid calendar dates.');
        }

        return $date->format('Y-m-d');
    }

    private function toCanonicalNumber(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (is_int($value)) {
            $number = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $number = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } elseif (is_string($value)) {
            $number = trim($value);

            if ($number === '') {
                return '';
            }
        } else {
            throw new InvalidArgumentException('Number values must be numeric scalars.');
        }

        if (preg_match('/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/D', $number, $parts) !== 1) {
            throw new InvalidArgumentException('Number values must use a decimal number format.');
        }

        $integer = ltrim(($parts[2] ?? '') !== '' ? $parts[2] : '0', '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim(($parts[3] ?? '') !== '' ? $parts[3] : ($parts[4] ?? ''), '0');
        $isZero = $integer === '0' && ($fraction === '' || trim($fraction, '0') === '');
        $sign = $isZero || ($parts[1] ?? '') !== '-' ? '' : '-';
        $exponent = $this->normalizeExponent($parts[5] ?? '');

        return $sign.$integer.($fraction === '' ? '' : '.'.$fraction).$exponent;
    }

    private function normalizeExponent(string $exponent): string
    {
        if ($exponent === '') {
            return '';
        }

        $negative = str_starts_with($exponent, '-');
        $digits = ltrim(ltrim($exponent, '+-'), '0');

        if ($digits === '') {
            return '';
        }

        return 'e'.($negative ? '-' : '').$digits;
    }

    private function validationCode(mixed $rule): string
    {
        $name = is_string($rule) ? $rule : (is_object($rule) ? class_basename($rule) : 'validation');
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? 'validation';
        $code = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', $snake) ?? 'validation');

        return trim($code, '_') !== '' ? trim($code, '_') : 'validation';
    }
}
