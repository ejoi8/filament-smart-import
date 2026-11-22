<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;

final class InvalidRowExclusionImportDefinition implements ImportDefinition
{
    public function key(): string
    {
        return 'preview-contacts-with-invalid-exclusion';
    }

    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Preview contacts with invalid row exclusion';
    }

    public function targetModel(): string
    {
        return ImportTargetModel::class;
    }

    public function fields(): array
    {
        return [
            new ImportField('name', 'Name', required: true),
            new ImportField('email', 'Email', required: true, rules: ['email']),
            new ImportField('api_token', 'API token', sensitive: true),
            new ImportField('department', 'Department'),
        ];
    }

    public function uniqueKeyFields(): array
    {
        return ['email'];
    }

    public function writePolicy(): ImportWritePolicy
    {
        return new ImportWritePolicy(ImportMode::Upsert, allowInvalidRowExclusion: true);
    }
}
