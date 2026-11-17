<?php

namespace SmartImport\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use SmartImport\Contracts\ImportDefinition;

final class TargetRecordMatcher
{
    private const QUERY_PARAMETER_BUDGET = 800;

    /**
     * Find existing target rows for a bounded set of normalized import rows.
     *
     * @param list<array<string, mixed>> $normalizedRows
     * @return list<array{
     *     change_type: string|null,
     *     target_type: string|null,
     *     target_id: string|null,
     *     errors: list<array{field: string, code: string, safe_message: string}>
     * }>
     */
    public function matchBatch(ImportDefinition $definition, array $normalizedRows, bool $lockForUpdate = false): array
    {
        if (! array_is_list($normalizedRows)) {
            throw new InvalidArgumentException('Normalized target-match rows must be provided as a list.');
        }

        $keyFields = $definition->uniqueKeyFields();
        $unmatched = array_fill(0, count($normalizedRows), [
            'change_type' => null,
            'target_type' => null,
            'target_id' => null,
            'errors' => [],
        ]);

        if ($keyFields === []) {
            return $unmatched;
        }

        $keySets = [];
        $rowSignatures = [];

        foreach ($normalizedRows as $index => $values) {
            if (! is_array($values)) {
                throw new InvalidArgumentException('Normalized target-match row values must be arrays.');
            }

            $key = $this->completeKey($keyFields, $values);

            if ($key === null) {
                $rowSignatures[$index] = null;

                continue;
            }

            $signature = $this->signature($key);
            $rowSignatures[$index] = $signature;
            $keySets[$signature] = $key;
        }

        if ($keySets === []) {
            return $unmatched;
        }

        $targetType = $definition->targetModel();
        $target = new $targetType();
        $found = [];
        $keysPerQuery = max(1, intdiv(self::QUERY_PARAMETER_BUDGET, count($keyFields)));

        foreach (array_chunk($keySets, $keysPerQuery, true) as $keyChunk) {
            $query = $target->newQuery();
            $columns = array_map(fn (string $field): string => $target->qualifyColumn($field), $keyFields);
            $columns[] = $target->qualifyColumn($target->getKeyName());
            $query->select(array_values(array_unique($columns)));
            $query->where(function (Builder $where) use ($target, $keyChunk, $keyFields): void {
                foreach ($keyChunk as $key) {
                    $where->orWhere(function (Builder $match) use ($target, $key, $keyFields): void {
                        foreach ($keyFields as $fieldName) {
                            $match->where($target->qualifyColumn($fieldName), '=', $key[$fieldName]);
                        }
                    });
                }
            });

            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            foreach ($query->cursor() as $targetRow) {
                $targetKey = [];

                foreach ($keyFields as $fieldName) {
                    $targetKey[$fieldName] = $targetRow->getRawOriginal($fieldName);
                }

                if ($this->completeKey($keyFields, $targetKey) === null) {
                    continue;
                }

                $signature = $this->signature($targetKey);

                if (! array_key_exists($signature, $keyChunk)) {
                    continue;
                }

                if (count($found[$signature] ?? []) < 2) {
                    $found[$signature][] = (string) $targetRow->getKey();
                }
            }
        }

        foreach ($rowSignatures as $index => $signature) {
            if ($signature === null) {
                continue;
            }

            $matches = $found[$signature] ?? [];

            if ($matches === []) {
                $unmatched[$index]['change_type'] = 'new';
            } elseif (count($matches) === 1) {
                $unmatched[$index] = [
                    'change_type' => 'matched',
                    'target_type' => $targetType,
                    'target_id' => $matches[0],
                    'errors' => [],
                ];
            } else {
                $unmatched[$index] = [
                    'change_type' => 'ambiguous',
                    'target_type' => null,
                    'target_id' => null,
                    'errors' => array_map(static fn (string $fieldName): array => [
                        'field' => $fieldName,
                        'code' => 'target_match_ambiguous',
                        'safe_message' => 'This key matches more than one existing target record.',
                    ], $keyFields),
                ];
            }
        }

        return $unmatched;
    }

    /** @param array<string, mixed> $normalizedValues */
    public function matchesKey(ImportDefinition $definition, array $normalizedValues, Model $target): bool
    {
        foreach ($definition->uniqueKeyFields() as $fieldName) {
            if (! array_key_exists($fieldName, $normalizedValues)) {
                return false;
            }

            $sourceValue = $normalizedValues[$fieldName];
            $targetValue = $target->getRawOriginal($fieldName);

            if ((! is_string($sourceValue) && ! is_int($sourceValue) && ! is_float($sourceValue) && ! is_bool($sourceValue))
                || (! is_string($targetValue) && ! is_int($targetValue) && ! is_float($targetValue) && ! is_bool($targetValue))
                || (string) $sourceValue !== (string) $targetValue) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $keyFields
     *  @param array<string, mixed> $values
     *  @return array<string, string|int|float|bool>|null
     */
    private function completeKey(array $keyFields, array $values): ?array
    {
        $key = [];

        foreach ($keyFields as $fieldName) {
            if (! array_key_exists($fieldName, $values)) {
                return null;
            }

            $value = $values[$fieldName];

            if ($value === null
                || (is_string($value) && trim($value) === '')
                || (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value))
                || (is_float($value) && ! is_finite($value))) {
                return null;
            }

            $key[$fieldName] = $value;
        }

        return $key;
    }

    /** @param array<string, string|int|float|bool> $key */
    private function signature(array $key): string
    {
        foreach ($key as $fieldName => $value) {
            $key[$fieldName] = (string) $value;
        }

        return hash('sha256', json_encode($key, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
