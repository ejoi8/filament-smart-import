<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\BlankCellPolicy;
use SmartImport\Enums\ImportMode;

final class BlankValueImportDefinition implements ImportDefinition
{
    public function key(): string
    {
        return 'blank-values';
    }

    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Blank values';
    }

    public function targetModel(): string
    {
        return ImportTargetModel::class;
    }

    public function fields(): array
    {
        return [
            new ImportField('email', 'Email', required: true, rules: ['email']),
            new ImportField('nickname', 'Nickname'),
            new ImportField('phone', 'Phone', blankCellPolicy: BlankCellPolicy::SetEmptyString),
            new ImportField('department', 'Department', blankCellPolicy: BlankCellPolicy::SetNull),
            new ImportField('note', 'Note', rules: ['nullable'], nullMarkers: ['[NULL]']),
        ];
    }

    public function uniqueKeyFields(): array
    {
        return ['email'];
    }

    public function writePolicy(): ImportWritePolicy
    {
        return new ImportWritePolicy(ImportMode::Upsert);
    }
}
