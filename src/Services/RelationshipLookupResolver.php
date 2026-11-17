<?php

namespace SmartImport\Services;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;

final class RelationshipLookupResolver
{
    /**
     * Resolve a set of source values with one bounded query per relationship and value chunk.
     * Comparisons are exact after the database returns candidate values; collation-only matches
     * do not silently resolve to a different source spelling.
     *
     * @param  list<mixed>  $values
     * @return list<array{value: mixed, error: array{code: string, safe_message: string}|null}>
     */
    public function resolveBatch(ImportDefinition $definition, ImportField $field, array $values): array
    {
        $lookup = $field->relationshipLookup;

        if ($lookup === null) {
            return array_map(static fn (mixed $value): array => ['value' => $value, 'error' => null], $values);
        }

        $outcomes = [];
        $requested = [];

        foreach ($values as $index => $value) {
            if ($value === null || $value === '') {
                $outcomes[$index] = ['value' => $value, 'error' => null];

                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                $outcomes[$index] = [
                    'value' => null,
                    'error' => [
                        'code' => 'relationship_value_invalid',
                        'safe_message' => 'The relationship reference must be a text or number value.',
                    ],
                ];

                continue;
            }

            $key = $this->valueKey($value);
            $requested[$key] = (string) $value;
        }

        $found = [];

        if ($requested !== []) {
            $target = new ($definition->targetModel());

            if (! method_exists($target, $lookup->relationMethod) || ! is_callable([$target, $lookup->relationMethod])) {
                throw new InvalidArgumentException('A registered relationship lookup no longer matches its target model.');
            }

            $relation = BelongsTo::noConstraints(fn () => $target->{$lookup->relationMethod}());

            if (! $relation instanceof BelongsTo || $field->name !== $relation->getForeignKeyName()) {
                throw new InvalidArgumentException('A registered relationship lookup no longer matches its belongs-to foreign key.');
            }

            $related = $relation->getRelated();
            $lookupColumn = $related->qualifyColumn($lookup->lookupColumn);
            $ownerColumn = $related->qualifyColumn($relation->getOwnerKeyName());

            foreach (array_chunk($requested, 500, true) as $valueChunk) {
                $matches = (clone $relation->getQuery())
                    ->whereIn($lookupColumn, array_values($valueChunk))
                    ->select([$lookupColumn, $ownerColumn])
                    ->cursor();

                foreach ($matches as $match) {
                    $matchedValue = $match->getAttribute($lookup->lookupColumn);

                    if (! is_string($matchedValue) && ! is_int($matchedValue) && ! is_float($matchedValue)) {
                        continue;
                    }

                    $key = $this->valueKey($matchedValue);

                    if (! array_key_exists($key, $valueChunk)) {
                        continue;
                    }

                    if (count($found[$key] ?? []) < 2) {
                        $found[$key][] = $match->getAttribute($relation->getOwnerKeyName());
                    }
                }
            }
        }

        foreach ($values as $index => $value) {
            if (array_key_exists($index, $outcomes)) {
                continue;
            }

            $matches = $found[$this->valueKey($value)] ?? [];

            if ($matches === []) {
                $outcomes[$index] = [
                    'value' => null,
                    'error' => [
                        'code' => 'relationship_not_found',
                        'safe_message' => 'No related record matches this value.',
                    ],
                ];
            } elseif (count($matches) > 1) {
                $outcomes[$index] = [
                    'value' => null,
                    'error' => [
                        'code' => 'relationship_ambiguous',
                        'safe_message' => 'More than one related record matches this value.',
                    ],
                ];
            } elseif ($matches[0] === null) {
                $outcomes[$index] = [
                    'value' => null,
                    'error' => [
                        'code' => 'relationship_not_found',
                        'safe_message' => 'No related record matches this value.',
                    ],
                ];
            } else {
                $outcomes[$index] = ['value' => $matches[0], 'error' => null];
            }
        }

        ksort($outcomes, SORT_NUMERIC);

        return array_values($outcomes);
    }

    private function valueKey(string|int|float $value): string
    {
        return (string) $value;
    }
}
