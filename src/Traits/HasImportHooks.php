<?php

namespace SmartImport\Traits;

use SmartImport\Data\ImportRowContext;
use SmartImport\Models\ImportSession;

/** No-op defaults for importer definitions that opt into only selected ImportHooks callbacks. */
trait HasImportHooks
{
    public function beforeImport(ImportSession $session): void {}

    public function beforeRowPersist(ImportRowContext $context): void {}

    public function afterRowPersist(ImportRowContext $context): void {}

    public function afterImport(ImportSession $session): void {}
}
