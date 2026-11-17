<?php

namespace SmartImport\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use Throwable;

final class ImporterRegistry
{
    /** @var array<string, ImportDefinition> */
    private array $definitions = [];

    /**
     * @param iterable<ImportDefinition> $definitions
     */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    /**
     * Register a definition object created by application-owned code.
     */
    public function register(ImportDefinition $definition): void
    {
        $key = $definition->key();

        if ($key === '' || trim($key) !== $key || mb_strlen($key) > 100) {
            throw new InvalidArgumentException('Importer keys must be non-empty, have no surrounding whitespace, and be at most 100 characters.');
        }

        $version = $definition->version();

        if ($version === '' || trim($version) !== $version || mb_strlen($version) > 100) {
            throw new InvalidArgumentException(sprintf(
                'Importer [%s] versions must be non-empty, have no surrounding whitespace, and be at most 100 characters.',
                $key,
            ));
        }

        if (array_key_exists($key, $this->definitions)) {
            throw new InvalidArgumentException(sprintf('Importer key [%s] is already registered.', $key));
        }

        $label = $definition->label();

        if (trim($label) === '') {
            throw new InvalidArgumentException(sprintf('Importer [%s] must have a non-empty display label.', $key));
        }

        $targetModel = $definition->targetModel();

        if (! class_exists($targetModel) || ! is_a($targetModel, Model::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Importer [%s] target [%s] must be an Eloquent model class.',
                $key,
                $targetModel,
            ));
        }

        $fields = $definition->fields();

        if (! array_is_list($fields) || $fields === []) {
            throw new InvalidArgumentException(sprintf('Importer [%s] must define a non-empty list of allowed fields.', $key));
        }

        $fieldNames = [];

        foreach ($fields as $field) {
            if (! $field instanceof ImportField) {
                throw new InvalidArgumentException(sprintf('Importer [%s] fields must be [%s] instances.', $key, ImportField::class));
            }

            if (array_key_exists($field->name, $fieldNames)) {
                throw new InvalidArgumentException(sprintf('Importer [%s] field [%s] is defined more than once.', $key, $field->name));
            }

            $fieldNames[$field->name] = true;
        }

        $target = new $targetModel();

        foreach ($fields as $field) {
            if ($field->relationshipLookup === null) {
                continue;
            }

            $lookup = $field->relationshipLookup;

            if (! method_exists($target, $lookup->relationMethod) || ! is_callable([$target, $lookup->relationMethod])) {
                throw new InvalidArgumentException(sprintf(
                    'Importer [%s] relationship lookup [%s] must name a public belongs-to method on its target model.',
                    $key,
                    $lookup->relationMethod,
                ));
            }

            try {
                $relation = BelongsTo::noConstraints(fn () => $target->{$lookup->relationMethod}());
            } catch (Throwable $exception) {
                throw new InvalidArgumentException(sprintf(
                    'Importer [%s] relationship lookup [%s] could not be resolved from its target model.',
                    $key,
                    $lookup->relationMethod,
                ), 0, $exception);
            }

            if (! $relation instanceof BelongsTo) {
                throw new InvalidArgumentException(sprintf(
                    'Importer [%s] relationship lookup [%s] must use an Eloquent belongs-to relationship.',
                    $key,
                    $lookup->relationMethod,
                ));
            }

            if ($field->name !== $relation->getForeignKeyName()) {
                throw new InvalidArgumentException(sprintf(
                    'Importer [%s] relationship lookup field [%s] must match the [%s] foreign key.',
                    $key,
                    $field->name,
                    $lookup->relationMethod,
                ));
            }
        }

        $uniqueKeyFields = $definition->uniqueKeyFields();

        if (! array_is_list($uniqueKeyFields)) {
            throw new InvalidArgumentException(sprintf('Importer [%s] unique key fields must be a list.', $key));
        }

        $uniqueKeySeen = [];

        foreach ($uniqueKeyFields as $fieldName) {
            if (! is_string($fieldName) || ! array_key_exists($fieldName, $fieldNames)) {
                throw new InvalidArgumentException(sprintf('Importer [%s] unique keys may contain only defined fields.', $key));
            }

            if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $fieldName) !== 1) {
                throw new InvalidArgumentException(sprintf('Importer [%s] unique key fields must be simple target column names.', $key));
            }

            if (array_key_exists($fieldName, $uniqueKeySeen)) {
                throw new InvalidArgumentException(sprintf('Importer [%s] unique key field [%s] is repeated.', $key, $fieldName));
            }

            $uniqueKeySeen[$fieldName] = true;
        }

        $writePolicy = $definition->writePolicy();

        if (! $writePolicy instanceof ImportWritePolicy) {
            throw new InvalidArgumentException(sprintf('Importer [%s] must return an [%s] write policy.', $key, ImportWritePolicy::class));
        }

        if ($writePolicy->mode !== ImportMode::Insert && $uniqueKeyFields === []) {
            throw new InvalidArgumentException(sprintf('Importer [%s] must define unique key fields for [%s] mode.', $key, $writePolicy->mode->value));
        }

        $this->definitions[$key] = $definition;
    }

    public function get(string $key): ImportDefinition
    {
        if (! array_key_exists($key, $this->definitions)) {
            throw new InvalidArgumentException(sprintf('Importer key [%s] is not registered.', $key));
        }

        return $this->definitions[$key];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->definitions);
    }

    /**
     * @return list<ImportDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
