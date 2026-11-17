<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;

final class SensitiveExportImportDefinition implements ImportDefinition
{
    public function key(): string
    {
        return 'sensitive-export';
    }

    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Sensitive export';
    }

    public function targetModel(): string
    {
        return ImportTargetModel::class;
    }

    public function fields(): array
    {
        return [
            new ImportField('name', 'Name', required: true),
            new ImportField('email', 'Email', required: true),
            new ImportField('api_token', 'API token', rules: ['email'], sensitive: true),
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
