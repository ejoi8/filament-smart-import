<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;

final class StagingImportDefinition implements ImportDefinition
{
    public function key(): string
    {
        return 'staging-contacts';
    }

    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Staging contacts';
    }

    public function targetModel(): string
    {
        return ImportTargetModel::class;
    }

    public function fields(): array
    {
        return [
            new ImportField('name', 'Name', required: true, rules: ['min:2'], transformers: ['trim']),
            new ImportField('email', 'Email', rules: ['nullable', 'email'], transformers: ['trim']),
        ];
    }

    public function uniqueKeyFields(): array
    {
        return ['email'];
    }

    public function writePolicy(): ImportWritePolicy
    {
        return new ImportWritePolicy(ImportMode::Insert, insertConflict: WriteConflictPolicy::Skip);
    }
}
