<?php

namespace SmartImport\Tests\Fixtures;

use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportHooks;
use SmartImport\Data\ImportRowContext;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Models\ImportSession;
use SmartImport\Traits\HasImportHooks;

final class HookedPreviewImportDefinition implements ImportDefinition, ImportHooks
{
    use HasImportHooks;

    /** @var list<array{hook: string, session_id?: string, row_id?: int}> */
    public static array $calls = [];

    public function key(): string
    {
        return (new PreviewImportDefinition())->key();
    }

    public function version(): string
    {
        return (new PreviewImportDefinition())->version();
    }

    public function label(): string
    {
        return (new PreviewImportDefinition())->label();
    }

    public function targetModel(): string
    {
        return (new PreviewImportDefinition())->targetModel();
    }

    public function fields(): array
    {
        return (new PreviewImportDefinition())->fields();
    }

    public function uniqueKeyFields(): array
    {
        return (new PreviewImportDefinition())->uniqueKeyFields();
    }

    public function writePolicy(): ImportWritePolicy
    {
        return new ImportWritePolicy(ImportMode::Upsert);
    }

    public function beforeImport(ImportSession $session): void
    {
        self::$calls[] = ['hook' => 'before_import', 'session_id' => (string) $session->getKey()];
    }

    public function beforeRowPersist(ImportRowContext $context): void
    {
        self::$calls[] = ['hook' => 'before_row', 'session_id' => $context->sessionId, 'row_id' => $context->rowId];
    }

    public function afterRowPersist(ImportRowContext $context): void
    {
        self::$calls[] = ['hook' => 'after_row', 'session_id' => $context->sessionId, 'row_id' => $context->rowId];
    }

    public function afterImport(ImportSession $session): void
    {
        self::$calls[] = ['hook' => 'after_import', 'session_id' => (string) $session->getKey()];
    }
}
