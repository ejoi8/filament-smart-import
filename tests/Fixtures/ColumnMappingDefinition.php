<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;

final class ColumnMappingDefinition implements ImportDefinition
{
    public function key(): string
    {
        return 'contacts';
    }

    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Contacts';
    }

    public function targetModel(): string
    {
        return ImportTargetModel::class;
    }

    public function fields(): array
    {
        return [
            new ImportField('name', 'Name', 'string', true, ['Full Name', 'Applicant']),
            new ImportField('email', 'Email address', 'email', false, ['E-mail', 'Email address']),
            new ImportField('no_kp', 'National ID', 'string', false, ['Identity Number', 'National ID']),
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
