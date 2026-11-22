<?php

namespace SmartImport\Contracts;

use SmartImport\Models\ImportSession;

/**
 * Establish application-owned tenant or other ambient scope while import work runs.
 * Implementations must restore any previous context in a finally block.
 */
interface ImportExecutionContext
{
    /** @template T @param callable(): T $callback @return T */
    public function run(ImportSession $session, callable $callback): mixed;
}
