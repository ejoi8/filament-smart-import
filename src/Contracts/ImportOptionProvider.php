<?php

namespace SmartImport\Contracts;

use SmartImport\Data\ImportOption;

/** Optional allowlist for simple, non-secret importer settings shown in shared adapters. */
interface ImportOptionProvider
{
    /** @return list<ImportOption> */
    public function importOptions(): array;
}
