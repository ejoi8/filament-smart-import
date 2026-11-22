<?php

namespace SmartImport\Services;

use InvalidArgumentException;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;

final class ColumnMapper
{
    /**
     * Suggest source-column mappings using normalized field names first, then declared aliases.
     * Suggestions that collide or have multiple candidates are returned for manual resolution.
     *
     * @param array<int, string|int|float|bool|null> $headers One-based source column indexes to raw header values.
     * @return array{
     *     columns: array<int, array{source_column: int, header: string|int|float|bool|null, normalized_header: ?string, suggested_field: ?string, match_type: ?string, status: string, candidates: list<string>}>,
     *     mapping: array<int, string>,
     *     unmapped_columns: list<int>,
     *     issues: list<array{code: string, source_columns: list<int>, message: string}>
     * }
     */
    public function suggest(ImportDefinition $definition, array $headers): array
    {
        $headers = $this->validateHeaders($headers);
        $fields = $this->fieldsByName($definition);
        $exactMatches = [];
        $aliasMatches = [];

        foreach ($fields as $field) {
            $normalizedName = $this->normalize($field->name);

            if ($normalizedName !== null && $normalizedName !== '') {
                $exactMatches[$normalizedName][] = $field->name;
            }

            foreach ($field->aliases as $alias) {
                $normalizedAlias = $this->normalize($alias);

                if ($normalizedAlias !== null && $normalizedAlias !== '') {
                    $aliasMatches[$normalizedAlias][] = $field->name;
                }
            }
        }

        $normalizedCounts = [];
        $normalizedHeaders = [];

        foreach ($headers as $sourceColumn => $header) {
            $normalized = $this->normalizeValue($header);
            $normalizedHeaders[$sourceColumn] = $normalized;

            if ($normalized !== null && $normalized !== '') {
                $normalizedCounts[$normalized] = ($normalizedCounts[$normalized] ?? 0) + 1;
            }
        }

        $columns = [];
        $issues = [];

        foreach ($headers as $sourceColumn => $header) {
            $normalized = $normalizedHeaders[$sourceColumn];

            if ($normalized === null) {
                $columns[$sourceColumn] = $this->columnResult($sourceColumn, $header, null, null, 'invalid_header', []);
                $issues[] = [
                    'code' => 'invalid_header_encoding',
                    'source_columns' => [$sourceColumn],
                    'message' => 'This header is not valid UTF-8 and needs manual review.',
                ];

                continue;
            }

            if ($normalized === '') {
                $columns[$sourceColumn] = $this->columnResult($sourceColumn, $header, '', null, 'empty_header', []);

                continue;
            }

            $matchType = 'exact';
            $candidates = $this->uniqueNames($exactMatches[$normalized] ?? []);

            if ($candidates === []) {
                $matchType = 'alias';
                $candidates = $this->uniqueNames($aliasMatches[$normalized] ?? []);
            }

            if (($normalizedCounts[$normalized] ?? 0) > 1) {
                $columns[$sourceColumn] = $this->columnResult($sourceColumn, $header, $normalized, null, 'header_collision', $candidates, $matchType);

                continue;
            }

            if (count($candidates) > 1) {
                $columns[$sourceColumn] = $this->columnResult($sourceColumn, $header, $normalized, null, 'ambiguous', $candidates, $matchType);
                $issues[] = [
                    'code' => 'ambiguous_field_match',
                    'source_columns' => [$sourceColumn],
                    'message' => 'This header matches more than one allowed importer field.',
                ];

                continue;
            }

            if ($candidates === []) {
                $columns[$sourceColumn] = $this->columnResult($sourceColumn, $header, $normalized, null, 'unmatched', []);

                continue;
            }

            $columns[$sourceColumn] = $this->columnResult($sourceColumn, $header, $normalized, $candidates[0], 'suggested', $candidates, $matchType);
        }

        $headerCollisionGroups = [];

        foreach ($normalizedHeaders as $sourceColumn => $normalized) {
            if ($normalized !== null && $normalized !== '' && ($normalizedCounts[$normalized] ?? 0) > 1) {
                $headerCollisionGroups[$normalized][] = $sourceColumn;
            }
        }

        foreach ($headerCollisionGroups as $sourceColumns) {
            $issues[] = [
                'code' => 'duplicate_normalized_headers',
                'source_columns' => $sourceColumns,
                'message' => 'These source headers normalize to the same value; resolve them by source column.',
            ];
        }

        foreach ($this->columnsBySuggestedField($columns) as $field => $sourceColumns) {
            if (count($sourceColumns) < 2) {
                continue;
            }

            foreach ($sourceColumns as $sourceColumn) {
                $columns[$sourceColumn]['suggested_field'] = null;
                $columns[$sourceColumn]['status'] = 'field_collision';
            }

            $issues[] = [
                'code' => 'multiple_columns_for_field',
                'source_columns' => $sourceColumns,
                'message' => sprintf('Multiple source columns suggest the same importer field [%s].', $field),
            ];
        }

        $mapping = [];
        $unmappedColumns = [];

        foreach ($columns as $sourceColumn => $column) {
            if ($column['status'] === 'suggested' && $column['suggested_field'] !== null) {
                $mapping[$sourceColumn] = $column['suggested_field'];
            } else {
                $unmappedColumns[] = $sourceColumn;
            }
        }

        return [
            'columns' => $columns,
            'mapping' => $mapping,
            'unmapped_columns' => $unmappedColumns,
            'issues' => $issues,
        ];
    }

    /**
     * Find a likely header row only when it maps enough importer fields and includes every required field.
     * Ties prefer the earlier row in the supplied sample.
     *
     * @param list<array{row_number: int, values: array<int, mixed>}> $sampleRows
     * @return array{row_number: int, matched_fields: list<string>, required_fields_matched: int, exact_matches: int}|null
     */
    public function detectHeaderRow(ImportDefinition $definition, array $sampleRows): ?array
    {
        $fields = $this->fieldsByName($definition);
        $requiredFields = array_keys(array_filter(
            $fields,
            static fn (ImportField $field): bool => $field->required,
        ));
        $minimumMatches = min(2, count($fields));
        $best = null;
        $bestScore = null;

        foreach ($sampleRows as $row) {
            if (! is_array($row)
                || ! is_int($row['row_number'] ?? null)
                || $row['row_number'] < 1
                || ! is_array($row['values'] ?? null)) {
                continue;
            }

            $suggestions = $this->suggest($definition, $row['values']);
            $matchedFields = array_values($suggestions['mapping']);
            $requiredMatched = count(array_intersect($requiredFields, $matchedFields));

            if (count($matchedFields) < $minimumMatches || $requiredMatched !== count($requiredFields)) {
                continue;
            }

            $exactMatches = count(array_filter(
                $suggestions['columns'],
                static fn (array $column): bool => $column['status'] === 'suggested' && $column['match_type'] === 'exact',
            ));
            $score = [$requiredMatched, count($matchedFields), $exactMatches];

            if ($bestScore !== null && $score <= $bestScore) {
                continue;
            }

            $bestScore = $score;
            $best = [
                'row_number' => $row['row_number'],
                'matched_fields' => $matchedFields,
                'required_fields_matched' => $requiredMatched,
                'exact_matches' => $exactMatches,
            ];
        }

        return $best;
    }

    /**
     * Validate a complete manual choice for every source column. A null choice explicitly ignores a column.
     *
     * @param array<int, string|int|float|bool|null> $headers
     * @param array<int, string|null> $mapping One-based source column index to canonical field name or null.
     * @return array{mapping: array<int, string>, ignored_columns: list<int>}
     */
    public function validateManualMapping(ImportDefinition $definition, array $headers, array $mapping): array
    {
        $headers = $this->validateHeaders($headers);
        $fields = $this->fieldsByName($definition);

        foreach ($mapping as $sourceColumn => $fieldName) {
            if (! is_int($sourceColumn) || ! array_key_exists($sourceColumn, $headers)) {
                throw new InvalidArgumentException(sprintf('Manual mapping references unknown source column [%s].', (string) $sourceColumn));
            }

            if ($fieldName !== null && ! is_string($fieldName)) {
                throw new InvalidArgumentException(sprintf('Manual mapping for source column [%d] must select an allowed field or null to ignore it.', $sourceColumn));
            }
        }

        $resolved = [];
        $ignoredColumns = [];
        $usedFields = [];

        foreach ($headers as $sourceColumn => $_header) {
            if (! array_key_exists($sourceColumn, $mapping)) {
                throw new InvalidArgumentException(sprintf('Source column [%d] needs a field choice or an explicit ignore decision.', $sourceColumn));
            }

            $fieldName = $mapping[$sourceColumn];

            if ($fieldName === null) {
                $ignoredColumns[] = $sourceColumn;

                continue;
            }

            if (! array_key_exists($fieldName, $fields)) {
                throw new InvalidArgumentException(sprintf('Field [%s] is not allowed by importer [%s].', $fieldName, $definition->key()));
            }

            if (array_key_exists($fieldName, $usedFields)) {
                throw new InvalidArgumentException(sprintf(
                    'Importer field [%s] is assigned to more than one source column; resolve the collision first.',
                    $fieldName,
                ));
            }

            $usedFields[$fieldName] = true;
            $resolved[$sourceColumn] = $fieldName;
        }

        return [
            'mapping' => $resolved,
            'ignored_columns' => $ignoredColumns,
        ];
    }

    /**
     * @param array<int, string|int|float|bool|null> $headers
     * @return array<int, string|int|float|bool|null>
     */
    public function validateHeaders(array $headers): array
    {
        if ($headers === []) {
            throw new InvalidArgumentException('At least one source column header is required for mapping.');
        }

        foreach ($headers as $sourceColumn => $header) {
            if (! is_int($sourceColumn) || $sourceColumn < 1) {
                throw new InvalidArgumentException('Source header keys must be positive, one-based column indexes.');
            }

            if (! is_string($header) && ! is_int($header) && ! is_float($header) && ! is_bool($header) && $header !== null) {
                throw new InvalidArgumentException(sprintf('Header for source column [%d] must be a scalar value or null.', $sourceColumn));
            }
        }

        ksort($headers, SORT_NUMERIC);

        return $headers;
    }

    /** @return array<string, ImportField> */
    private function fieldsByName(ImportDefinition $definition): array
    {
        $fields = $definition->fields();

        if (! array_is_list($fields) || $fields === []) {
            throw new InvalidArgumentException(sprintf('Importer [%s] must define a non-empty list of allowed fields.', $definition->key()));
        }

        $byName = [];

        foreach ($fields as $field) {
            if (! $field instanceof ImportField) {
                throw new InvalidArgumentException(sprintf('Importer [%s] fields must be [%s] instances.', $definition->key(), ImportField::class));
            }

            if (array_key_exists($field->name, $byName)) {
                throw new InvalidArgumentException(sprintf('Importer [%s] defines field [%s] more than once.', $definition->key(), $field->name));
            }

            $byName[$field->name] = $field;
        }

        return $byName;
    }

    private function normalizeValue(string|int|float|bool|null $value): ?string
    {
        return $this->normalize($value === null ? '' : (string) $value);
    }

    private function normalize(string $value): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        if (class_exists(\Normalizer::class)) {
            $unicodeNormalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);

            if (is_string($unicodeNormalized)) {
                $value = $unicodeNormalized;
            }
        }

        $value = mb_convert_case(trim($value), MB_CASE_FOLD, 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '_', $value);

        if (! is_string($normalized)) {
            return null;
        }

        return trim($normalized, '_');
    }

    /** @param list<string> $names @return list<string> */
    private function uniqueNames(array $names): array
    {
        return array_values(array_unique($names));
    }

    /**
     * @param array<int, array{source_column: int, header: string|int|float|bool|null, normalized_header: ?string, suggested_field: ?string, match_type: ?string, status: string, candidates: list<string>}> $columns
     * @return array<string, list<int>>
     */
    private function columnsBySuggestedField(array $columns): array
    {
        $groups = [];

        foreach ($columns as $sourceColumn => $column) {
            if ($column['status'] === 'suggested' && $column['suggested_field'] !== null) {
                $groups[$column['suggested_field']][] = $sourceColumn;
            }
        }

        return $groups;
    }

    /**
     * @param list<string> $candidates
     * @return array{source_column: int, header: string|int|float|bool|null, normalized_header: ?string, suggested_field: ?string, match_type: ?string, status: string, candidates: list<string>}
     */
    private function columnResult(
        int $sourceColumn,
        string|int|float|bool|null $header,
        ?string $normalized,
        ?string $suggestedField,
        string $status,
        array $candidates,
        ?string $matchType = null,
    ): array {
        return [
            'source_column' => $sourceColumn,
            'header' => $header,
            'normalized_header' => $normalized,
            'suggested_field' => $suggestedField,
            'match_type' => $matchType,
            'status' => $status,
            'candidates' => $candidates,
        ];
    }
}
