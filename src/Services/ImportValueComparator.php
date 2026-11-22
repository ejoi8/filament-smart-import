<?php

namespace SmartImport\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use Throwable;

/** Shared comparison and keyed target snapshot rules used by preview and execution. */
final class ImportValueComparator
{
    public function same(mixed $left, mixed $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return (is_int($left) || is_float($left))
            && (is_int($right) || is_float($right))
            && is_finite((float) $left)
            && is_finite((float) $right)
            && $left == $right;
    }

    public function sameForField(ImportField $field, ?Model $target, mixed $left, mixed $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (! $this->isBooleanField($field, $target)) {
            return $this->same($left, $right);
        }

        $leftBoolean = $this->booleanRepresentation($left);
        $rightBoolean = $this->booleanRepresentation($right);

        return $leftBoolean !== null && $rightBoolean !== null && $leftBoolean === $rightBoolean;
    }

    /** @param array<string, mixed> $values */
    public function targetHasChanges(Model $target, array $values, ImportDefinition $definition): bool
    {
        $fields = [];

        foreach ($definition->fields() as $field) {
            if ($field instanceof ImportField) {
                $fields[$field->name] = $field;
            }
        }

        foreach ($values as $fieldName => $value) {
            if (! is_string($fieldName)
                || ! isset($fields[$fieldName])
                || ! $this->sameForField($fields[$fieldName], $target, $target->getRawOriginal($fieldName), $value)) {
                return true;
            }
        }

        return false;
    }

    private function isBooleanField(ImportField $field, ?Model $target): bool
    {
        if (in_array(strtolower($field->type), ['bool', 'boolean'], true)) {
            return true;
        }

        $cast = $target?->getCasts()[$field->name] ?? null;

        if (! is_string($cast)) {
            return false;
        }

        $castType = strtolower(explode(':', $cast, 2)[0]);

        return in_array($castType, ['bool', 'boolean'], true);
    }

    private function booleanRepresentation(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => null,
        };
    }

    /** @param list<string> $fieldNames */
    public function targetSnapshotToken(Model $target, array $fieldNames): string
    {
        $appKey = config('app.key');

        if (! is_string($appKey) || $appKey === '') {
            throw new DomainException('Configure the Laravel application key before generating a target change preview.');
        }

        $fieldNames = array_values(array_unique($fieldNames));
        sort($fieldNames, SORT_STRING);
        $fields = [];

        foreach ($fieldNames as $fieldName) {
            if (! is_string($fieldName) || $fieldName === '') {
                throw new DomainException('A target snapshot contains an invalid mapped field.');
            }

            $fields[$fieldName] = $target->getRawOriginal($fieldName);
        }

        $payload = [
            'version' => 1,
            'model' => $target::class,
            'key' => $target->getKey(),
            'fields' => $fields,
        ];

        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new DomainException('The target record cannot be represented safely for a change preview.', 0, $exception);
        }

        return hash_hmac('sha256', $encoded, $appKey);
    }

    /** @param array<string, mixed> $snapshot
     *  @param list<string> $fieldNames
     */
    public function matchesTargetSnapshot(Model $target, array $snapshot, array $fieldNames): bool
    {
        if (($snapshot['version'] ?? null) !== 1 || ! is_string($snapshot['token'] ?? null)) {
            return false;
        }

        try {
            return hash_equals($snapshot['token'], $this->targetSnapshotToken($target, $fieldNames));
        } catch (DomainException) {
            return false;
        }
    }
}
