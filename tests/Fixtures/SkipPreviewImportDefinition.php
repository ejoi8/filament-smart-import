<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;

final class SkipPreviewImportDefinition implements ImportDefinition
{
    public function key(): string
    {
        return 'skip-preview-contacts';
    }

    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Skip preview contacts';
    }

    public function targetModel(): string
    {
        return ImportTargetModel::class;
    }

    public function fields(): array
    {
        return (new PreviewImportDefinition())->fields();
    }

    public function uniqueKeyFields(): array
    {
        return ['email'];
    }

    public function writePolicy(): ImportWritePolicy
    {
        return new ImportWritePolicy(ImportMode::Skip);
    }
}
