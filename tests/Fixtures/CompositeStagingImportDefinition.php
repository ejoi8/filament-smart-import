<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;

final class CompositeStagingImportDefinition implements ImportDefinition
{
    public function key(): string
    {
        return 'composite-staging';
    }

    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Composite staging';
    }

    public function targetModel(): string
    {
        return ImportTargetModel::class;
    }

    public function fields(): array
    {
        return [
            new ImportField('name', 'Name', required: true, transformers: ['trim']),
            new ImportField('department', 'Department', required: true, transformers: ['trim']),
        ];
    }

    public function uniqueKeyFields(): array
    {
        return ['name', 'department'];
    }

    public function writePolicy(): ImportWritePolicy
    {
        return new ImportWritePolicy(ImportMode::Insert, insertConflict: WriteConflictPolicy::Skip);
    }
}
