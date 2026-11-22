<?php

namespace SmartImport\Services;

use DomainException;
use SmartImport\Contracts\ImportExecutionContext;
use SmartImport\Models\ImportSession;

/** Default context: tenant-free imports only. Tenant-aware hosts must configure their own scope. */
final class NoTenantImportExecutionContext implements ImportExecutionContext
{
    public function run(ImportSession $session, callable $callback): mixed
    {
        if ($session->tenant_id !== null) {
            throw new DomainException('A tenant-aware Smart Import execution context must be configured for this import.');
        }

        return $callback();
    }
}
