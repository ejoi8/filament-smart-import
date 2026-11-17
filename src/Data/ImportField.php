<?php

namespace SmartImport\Data;

use InvalidArgumentException;
use SmartImport\Contracts\FieldTransformer;
use SmartImport\Enums\BlankCellPolicy;

final readonly class ImportField
{
    /** @var list<string> */
    public const BUILT_IN_TRANSFORMERS = ['trim', 'string', 'date', 'number'];

    /**
     * @param list<string> $aliases
     * @param list<mixed> $rules Laravel validation rules for this field.
     * @param list<string|FieldTransformer> $transformers Built-in names or application-owned transformer objects.
     * @param list<string> $nullMarkers Exact source strings that intentionally normalize to null.
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = 'string',
        public bool $required = false,
        public array $aliases = [],
        public array $rules = [],
        public array $transformers = [],
        public ?RelationshipLookup $relationshipLookup = null,
        public BlankCellPolicy $blankCellPolicy = BlankCellPolicy::Preserve,
        public array $nullMarkers = [],
        public bool $sensitive = false,
    ) {
        if ($name === '' || trim($name) !== $name) {
            throw new InvalidArgumentException('Import field names must be non-empty and have no surrounding whitespace.');
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException('Import field labels must not be empty.');
        }

        if ($type === '' || trim($type) !== $type) {
            throw new InvalidArgumentException('Import field types must be non-empty and have no surrounding whitespace.');
        }

        if (! array_is_list($aliases)) {
            throw new InvalidArgumentException('Import field aliases must be provided as a list of strings.');
        }

        if (! array_is_list($rules)) {
            throw new InvalidArgumentException('Import field validation rules must be provided as a list.');
        }

        foreach ($rules as $rule) {
            if (! is_string($rule) && ! is_object($rule) && ! is_callable($rule)) {
                throw new InvalidArgumentException('Import field validation rules must use Laravel rule strings, rule objects, or callables.');
            }
        }

        if (! array_is_list($transformers)) {
            throw new InvalidArgumentException('Import field transformers must be provided as an ordered list.');
        }

        foreach ($transformers as $transformer) {
            if (is_string($transformer) && in_array($transformer, self::BUILT_IN_TRANSFORMERS, true)) {
                continue;
            }

            if (! $transformer instanceof FieldTransformer) {
                throw new InvalidArgumentException(sprintf(
                    'Import field transformers must be one of [%s] or implement [%s].',
                    implode(', ', self::BUILT_IN_TRANSFORMERS),
                    FieldTransformer::class,
                ));
            }
        }

        if (! array_is_list($nullMarkers)) {
            throw new InvalidArgumentException('Import field null markers must be provided as a list of strings.');
        }

        $seenNullMarkers = [];

        foreach ($nullMarkers as $marker) {
            if (! is_string($marker) || $marker === '' || trim($marker) !== $marker || ! mb_check_encoding($marker, 'UTF-8')) {
                throw new InvalidArgumentException('Import field null markers must be non-empty, trimmed UTF-8 strings.');
            }

            if (array_key_exists($marker, $seenNullMarkers)) {
                throw new InvalidArgumentException('Import field null markers must be unique.');
            }

            $seenNullMarkers[$marker] = true;
        }

        $seenAliases = [];

        foreach ($aliases as $alias) {
            if (! is_string($alias) || $alias === '' || trim($alias) !== $alias || ! mb_check_encoding($alias, 'UTF-8')) {
                throw new InvalidArgumentException('Import field aliases must be non-empty, trimmed UTF-8 strings.');
            }

            $normalizedAlias = mb_convert_case($alias, MB_CASE_FOLD, 'UTF-8');

            if (array_key_exists($normalizedAlias, $seenAliases)) {
                throw new InvalidArgumentException('Import field aliases must be unique within a field, ignoring case.');
            }

            $seenAliases[$normalizedAlias] = true;
        }
    }
}
