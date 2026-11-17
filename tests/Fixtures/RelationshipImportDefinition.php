<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Data\RelationshipLookup;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;

final class RelationshipImportDefinition implements ImportDefinition
{
    public function __construct(
        private ?RelationshipLookup $lookup = null,
        private string $fieldName = 'customer_id',
        private string $model = RelationshipImportTarget::class,
    ) {}

    public function key(): string
    {
        return 'relationship-import';
    }

    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Relationship import';
    }

    public function targetModel(): string
    {
        return $this->model;
    }

    public function fields(): array
    {
        return [new ImportField(
            $this->fieldName,
            'Customer',
            type: 'integer',
            required: true,
            rules: ['integer'],
            transformers: ['trim'],
            relationshipLookup: $this->lookup ?? new RelationshipLookup('customer', 'email'),
        )];
    }

    public function uniqueKeyFields(): array
    {
        return [];
    }

    public function writePolicy(): ImportWritePolicy
    {
        return new ImportWritePolicy(ImportMode::Insert, insertConflict: WriteConflictPolicy::Skip);
    }
}
