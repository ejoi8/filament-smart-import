# Tenant execution context and staged data protection

Smart Import stores the tenant identifier selected by trusted host intake code on each import session. It does not infer a tenant from request input, serialize an authenticated user into a job, or change Laravel's global authentication state.

## Configure tenant-aware worker scope

Tenant-free sessions work with the default context. A session with a non-null `tenant_id` fails closed unless the host configures an implementation of `SmartImport\Contracts\ImportExecutionContext`:

```php
// config/smart-import.php
'execution' => [
    'context' => App\Imports\SmartImportExecutionContext::class,
],
```

The host implementation receives the persisted `ImportSession`; it should establish the app's tenant context from that record and restore the previous ambient value in `finally`:

```php
public function run(ImportSession $session, callable $callback): mixed
{
    if (! is_string($session->tenant_id) || $session->tenant_id === '') {
        throw new DomainException('The persisted tenant is unavailable.');
    }

    $previous = TenantContext::current();
    TenantContext::set($session->tenant_id);

    try {
        return $callback();
    } finally {
        TenantContext::set($previous);
    }
}
```

The shared engine runs this boundary for queued staging, synchronous and queued preview building, review corrections that resolve relationships or targets, and the complete execution chunk including importer lifecycle hooks and terminal callbacks. A tenant-aware host must also make its target model and relationship queries use that ambient context. The contract is deliberately package-owned and does not depend on a tenancy vendor. Intake must pass a server-verified tenant ID; existing session scopes and Gates continue to authorize browser and CLI operations.

For background workers, publish the package config and make the configured context class available in every worker process. Do not rotate tenant state between calls outside this contract.

## Staged values and encryption

For new staging revisions, fields declared with `ImportField::sensitive: true` are encrypted in the `raw_values`, `mapped_values`, and `normalized_values` JSON payloads. Only source positions mapped to sensitive fields are encrypted in the raw payload. The payload envelope is versioned and uses Laravel's application encrypter. The package reads legacy unwrapped arrays as plaintext and never rewrites old rows as a migration shortcut. Unsupported envelopes or decryption failures stop processing rather than falling back to plaintext.

New row source fingerprints and persisted file-duplicate key hashes use keyed HMAC signatures. Their session options record the signature version. Older sessions without that option continue to use the legacy SHA-256 comparison. Configure a stable random secret with at least 32 characters as `SMART_IMPORT_INDEX_KEY`; when unset, the package uses `APP_KEY`. Keep this key stable while imports or retained indexed rows need to be resumed or compared. Changing it invalidates version 2 row fingerprints and duplicate-key hashes; an operator must preserve the old key or deliberately restage eligible work. The package does not perform an automatic rehash or migration. Laravel encryption-key rotation must retain old keys for decryption using the Laravel version's supported previous-key mechanism.

Encryption is scoped to sensitive staged cells and the existing sensitive preview values. The source file remains plaintext on the configured private storage disk; parser temporary files may contain plaintext while reading. Non-sensitive staged cells remain readable JSON. Failure exports decrypt only at the service boundary and apply the existing per-field authorization/redaction rules. The package does not put cell values into its own audit messages or safe error summaries.

Per-cell encryption adds ciphertext size and encryption work proportional to the number of populated sensitive cells. This release has no throughput claim for that overhead; benchmark representative files before choosing high sensitive-field counts or raising row/file limits.

Database and storage backups inherit the data they contain: staged sensitive cells are encrypted, while source files and non-sensitive cells are not encrypted by this package. Protect backups and `APP_KEY`/`SMART_IMPORT_INDEX_KEY` as host secrets. Re-encrypting old plaintext rows, source files, or backups is an operator-managed migration/retention decision.
