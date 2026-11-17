<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;

final class ExportTemplateImportDefinition implements ImportDefinition
{
    public function key(): string
    {
        return 'export-template';
    }

    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Export template';
    }

    public function targetModel(): string
    {
        return ImportTargetModel::class;
    }

    public function fields(): array
    {
        return [
            new ImportField('name', 'Name', required: true),
            new ImportField('email', 'Email address', type: 'email'),
        ];
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
