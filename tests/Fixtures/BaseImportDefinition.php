<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;

abstract class BaseImportDefinition implements ImportDefinition
{
    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Test importer';
    }

    public function targetModel(): string
    {
        return ImportTargetModel::class;
    }

    public function fields(): array
    {
        return [
            new ImportField('name', 'Name', 'string', true),
            new ImportField('email', 'Email address', 'email'),
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
