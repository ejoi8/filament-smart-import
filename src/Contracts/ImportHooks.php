<?php

namespace SmartImport\Contracts;

use SmartImport\Data\ImportRowContext;
use SmartImport\Models\ImportSession;

/** Optional importer lifecycle hooks. Hooks must be deterministic before commit and idempotent after commit. */
interface ImportHooks
{
    public function beforeImport(ImportSession $session): void;

    public function beforeRowPersist(ImportRowContext $context): void;

    public function afterRowPersist(ImportRowContext $context): void;

    public function afterImport(ImportSession $session): void;
}
