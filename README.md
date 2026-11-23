# Smart Import for Laravel

A reusable Laravel import engine with an optional Filament 5 Import Hub. Importer definitions, validation, staging, previews, writes, queue execution, progress, history, recovery, and notifications all use one shared Laravel core. The Filament Hub, Resource shortcuts, and Artisan commands are thin adapters over that same core.

> **Development status:** This package is pre-release. It is not tagged on Packagist and has no finalized license. Use this build in development or staging first, review the migrations, and verify the queue and database behavior in your own application before production use.

## Features

- **Private intake:** browser uploads and restricted-folder SFTP discovery share one intake service. Files stay on a private disk, are checksum-verified, and are never auto-imported.
- **Explicit importers:** application code declares the target Eloquent model, allowlisted fields, aliases, validation rules, transformations, relationships, unique keys, and an explicit write policy.
- **Mapping and staging:** sheet/header selection, CSV delimiter/encoding options, suggested and manual column mapping, reusable mapping presets, relationship lookup, and row validation without writing target records.
- **Persisted change preview:** every staged row gets a reviewed action (`create`, `update`, `skip`, or `fail`), a reason, the matched target identifier, and before/after field diffs. Previewing never writes target records.
- **Duplicate, new, updated, and unchanged detection:** file-level duplicate content, in-file duplicate keys, existing-record matches, and no-op records are classified before confirmation (see [Data classification](#data-classification)).
- **Safe queued execution:** confirmation freezes the reviewed revision, queues bounded row chunks, revalidates each row, rechecks duplicates and target matches under locks, and writes only allowlisted fields. Repeated job delivery does not write twice.
- **Durable progress and recovery:** progress comes from committed database rows and survives refresh, worker restarts, and browser closure. Stale work can be recovered, cancelled sessions finish at a row boundary, and failed rows can be retried without repeating successful rows.
- **History, audit, and exports:** scoped history, row outcomes, before/after diffs, attempt history, audit entries, and export-gated CSV reports.
- **Sensitive fields:** values declared sensitive are encrypted in staged payloads and previews; reveal and edit are protected by separate Gate abilities.
- **Notifications:** a durable outbox delivers terminal completion, failure, and cancellation notifications without duplicating records.
- **Optional Filament 5 UI:** Import Inbox, mapping, review, active imports, progress, history, and a Resource shortcut action.

## Data classification

Classification depends on the importer declaring `uniqueKeyFields()`. Those key fields identify an existing target record and also drive within-file duplicate checks.

| Classification | When it applies | Import result |
|---|---|---|
| Duplicate file | Accepted file content (SHA-256) matches an earlier intake record | New record is retained and linked through `possible_duplicate_of`; discovery/upload does not discard it |
| Duplicate row in file | Two or more rows share the same complete, non-blank configured key | Every row in the group is marked invalid with `duplicate_in_file` |
| New | The complete key has no matching target record | `create` |
| Updated | The complete key matches one target record and at least one mapped value differs | `update` |
| Unchanged (redundant) | The complete key matches one target record and no mapped value differs | `skip` with reason `unchanged`; no target write occurs |
| Ambiguous | The complete key matches more than one target record | Invalid at staging and `fail` at preview/execution |
| Unresolved | The key is missing, blank, or not mapped | No target lookup; update/upsert/skip modes fail closed |

Important behavior:

- `update`, `upsert`, and `skip` modes require a configured unique key. `insert` mode may omit it, but then the engine cannot classify duplicates, existing records, or unchanged rows; only database constraints can reject a conflict.
- Map every unique key field. A missing key value leaves the row unclassified.
- Duplicate groups are not de-duplicated automatically. Correct the key values or explicitly exclude invalid rows at confirmation when the importer allows it.
- Matching uses all key fields together and honors the target model's Eloquent global scopes, including host tenant scopes.
- Unchanged detection compares only the mapped, normalized values that will be written. Blank cells are preserved by default and therefore usually do not create a change. Comparison is strict except for declared `bool`/`boolean` fields, which accept `true`/`1`/`'1'` and `false`/`0`/`'0'` as equivalent.
- Preview actions are estimates. Execution rechecks duplicates, target matches, and the reviewed target snapshot, and fails closed if anything changed.

## Requirements

- PHP 8.2 or newer with Laravel 12; PHP 8.3 or newer with Laravel 13.
- PhpSpreadsheet 5, installed as a package dependency.
- An asynchronous Laravel queue connection for staging, preview, and execution jobs.
- A private Laravel storage disk for import files.
- Filament 5 only if you want the Hub or Resource action.
- A lock-capable cache store if you use SFTP discovery.
- Package tables and imported target models on the same database connection, so row writes and counters can commit atomically.
- Node.js and the package's npm dependencies are only needed when changing package Blade styles; host applications do not need them.

## Installation

### Install from GitHub

The package is not published to Packagist yet. Run these commands from your Laravel application's directory:

```bash
composer config repositories.filament-smart-import vcs https://github.com/ejoi8/filament-smart-import.git
composer require smart-import/laravel-smart-import:dev-main
```

Then publish the configuration and run the package migrations:

```bash
php artisan vendor:publish --tag=smart-import-config
php artisan migrate
```

If you use the Filament Hub, register the plugin and publish its assets as described in [Enable the Filament Hub](#enable-the-filament-hub). `dev-main` is an in-development branch, not a tagged release. Use it in development or staging while the package remains pre-release.

### Install from a local checkout

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../filament-smart-import",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "smart-import/laravel-smart-import": "*"
  }
}
```

```bash
composer update smart-import/laravel-smart-import
php artisan vendor:publish --tag=smart-import-config
php artisan migrate
```

Laravel discovers the package service provider through Composer. Back up production data and review migrations before applying them.

## Configuration

The published `config/smart-import.php` controls storage, upload limits, reading limits, queues, execution, security, notifications, previews, recovery, progress polling, authorization scopes, and Filament panels. Key settings:

| Setting | Default | Purpose |
|---|---|---|
| `storage.disk` | `local` | Private disk for uploads and discovered files |
| `storage.root` | `smart-import` | Private storage root; never point this at a public web directory |
| `uploads.max_file_size_bytes` | 10 MiB | Maximum accepted file size |
| `uploads.max_files_per_actor_per_hour` | 20 | Per-actor upload quota |
| `reading.max_rows` | 100000 | Maximum data rows per import |
| `reading.max_columns` | 256 | Maximum source columns |
| `reading.max_sheets` | 20 | Maximum worksheets |
| `queue.connection` | `queue.default` | Queue connection used by staging, preview, execution, and outbox jobs |
| `queue.name` | `imports` | Queue name |
| `execution.chunk_size` | 100 | Rows per execution job (1-1000) |
| `preview.async_threshold_rows` | 1000 | Preview size that switches to a queued preview |
| `recovery.stale_after_seconds` | 900 | Age before work is considered stale |
| `recovery.max_recoveries` | 3 | Recovery limit per attempt |
| `security.index_key` | `APP_KEY` | Stable HMAC key for indexed signatures; set `SMART_IMPORT_INDEX_KEY` explicitly |

Set a stable index key with at least 32 characters:

```dotenv
SMART_IMPORT_INDEX_KEY=replace-with-a-long-random-value
```

Changing this key invalidates version 2 row fingerprints and duplicate-key hashes; keep it stable or deliberately restage affected work. Do not rely on `APP_KEY` here: rotating `APP_KEY` without retaining old keys can invalidate in-flight previews and indexed comparisons.

Start a queue worker for the configured connection and queue, for example:

```bash
php artisan queue:work database --queue=imports --timeout=55 --tries=3
```

Choose `--timeout` above the normal duration of one execution chunk and keep `retry_after` above the worker timeout. See [queue worker operation](docs/queue-workers.md).

### Enable the Filament Hub

Install Filament 5 in the host application, register the plugin on the intended panel, and explicitly authorize that panel:

```php
use Filament\Panel;
use SmartImport\Filament\SmartImportPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->plugins([SmartImportPlugin::make()]);
}
```

```php
// config/smart-import.php
'filament' => [
    'panels' => [
        'admin' => 'accessSmartImportHub',
    ],
],
```

After registering the plugin, publish Filament assets so the Hub stylesheet is available:

```bash
php artisan filament:assets
```

The stylesheet is published to `public/css/smart-import/smart-import.css`. The Hub ships its own compiled CSS; a custom theme is not required, and host applications do not need to add package views to their Tailwind sources. Run `filament:assets` after installing or updating the package. `filament:upgrade` and the usual Composer post-autoload hook also publish it.

Registering a panel without adding it to this map produces no Hub pages for that panel.

Custom themes may still include the package views if you want to override Hub styling:

```css
@source '../../../../vendor/smart-import/laravel-smart-import/resources/views/**/*';
```

See [the Hub guide](docs/filament-hub.md) for details.

## Define an importer

An importer is application code. It declares the target model, allowed fields, stable version, optional matching key, and explicit write policy.

```php
<?php

namespace App\Imports;

use App\Models\Contact;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;

final class ContactImportDefinition implements ImportDefinition
{
    public function key(): string
    {
        return 'contacts';
    }

    public function version(): string
    {
        return '1';
    }

    public function label(): string
    {
        return 'Contacts';
    }

    public function targetModel(): string
    {
        return Contact::class;
    }

    public function fields(): array
    {
        return [
            new ImportField('name', 'Name', required: true),
            new ImportField('email', 'Email', required: true, rules: ['email']),
            new ImportField('phone', 'Phone', rules: ['nullable']),
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
```

Register only application-owned definitions:

```php
// config/smart-import.php
'importers' => [
    App\Imports\ContactImportDefinition::class,
],
```

Increment `version()` whenever fields, validation, transformations, unique keys, or write behavior change. Read [importer definitions](docs/importer-definitions.md) for:

- write modes and conflict policies (`insert`, `update`, `upsert`, `skip`);
- composite unique keys and ambiguous matches;
- aliases, transformers, blank-cell policies, null markers, and explicit nulls;
- belongs-to relationship lookups;
- importer-defined operator options;
- lifecycle hooks and importer-specific validation rules;
- sensitive fields and boolean unchanged comparison.

### Resource shortcut

Use the same Hub from a Filament Resource without duplicating mapping or processing logic:

```php
use SmartImport\Filament\Actions\SmartImportAction;

protected function getHeaderActions(): array
{
    return [SmartImportAction::make('contacts')];
}
```

The action only opens the Hub with the importer preselected. The operator still assigns, maps, stages, reviews, and confirms.

## Run an import

### Filament Hub

1. Upload a CSV, XLSX, or XLS file, or discover a file from the private SFTP inbox.
2. Assign an authorized importer, choose the worksheet and header row, set CSV options if needed, and map source columns. Save or apply a compatible mapping preset when available.
3. Start validation staging. Staging validates rows and identifies duplicates and existing target matches; it does not write targets.
4. Generate the change preview. Review the planned creates, updates, unchanged rows, conflicts, and field diffs. Use the validation, match-result, and duplicate filters.
5. Correct staged rows where needed. A correction increments the staging revision and invalidates the saved preview.
6. Confirm and queue the reviewed revision. Invalid rows must be corrected, or explicitly excluded when the importer allows it.
7. Follow **Active Imports** for saved progress, cancel queued work if needed, and use **Import History** for terminal results, row outcomes, attempts, audit entries, and exports.
8. After a completed attempt with failed rows, use **Retry failed rows**. Successful rows are not written again.

### Artisan

The CLI uses explicit actor and guard IDs and the same staged review workflow.

```bash
# Discover and register completed SFTP files; this never stages or imports.
php artisan smart-import:discover --actor=123 --guard=web

# Stage an existing authorized session.
php artisan smart-import:stage {session} \
    --actor=123 \
    --guard=web \
    --importer=contacts \
    --sheet=0 \
    --header-row=1 \
    --mapping='{"1":"name","2":"email"}'

# Generate the read-only preview for the current staging revision.
php artisan smart-import:preview {session} --actor=123 --guard=web --revision=1

# Queue exactly the reviewed revision.
php artisan smart-import:confirm {session} --actor=123 --guard=web --revision=1 --confirm
```

Operational commands:

```bash
# Inspect stale work without changing it.
php artisan smart-import:recover --dry-run

# Requeue eligible stale staging or execution work.
php artisan smart-import:recover --limit=100

# Deliver pending terminal notifications through the outbox.
php artisan smart-import:deliver-outbox --limit=100

# Inspect retention candidates; this does not delete anything.
php artisan smart-import:retention-dry-run --older-than=365 --limit=100
```

`--mapping` maps one-based source column indexes to field names or `null` for an explicit ignore. Use `--preset={id}` instead of `--mapping` to apply a saved authorized preset. See the [Artisan workflow](docs/cli.md) for flags, JSON output, and exit codes.

### Programmatic use

The Hub and Artisan commands call the same services. If you are building another adapter, use `FileIntakeService`, `ImportCoordinator`, `ImportStagingService`, `ImportPreviewService`, `ImportExecutionService`, and `ImportProgressService` rather than duplicating mapping, validation, or write logic. Every service rechecks authorization; keep actor and tenant context explicit.

## Authorization

The host application owns users, roles, and tenant rules. Define `accessSmartImportHub` plus the package operation abilities. They are checked by the shared services as well as the UI.

| Ability | Subject | Used for |
|---|---|---|
| `accessSmartImportHub` | Panel | Exposing the configured Filament Hub |
| `viewAny` | `ImportSession::class` | Listing Inbox, Active Imports, and History sessions |
| `create` | `ImportSession::class` | Registering uploaded or discovered intake files |
| `createForTenant` | Tenant ID | Creating intake for an explicit trusted tenant |
| `view` | `ImportSession` | Reading a session, preview, progress, or history |
| `update` | `ImportSession` | Assigning an importer, mapping, staging, correcting rows, rejecting, or archiving |
| `queue` | `ImportSession` | Confirming and queueing an import |
| `cancel` | `ImportSession` | Requesting cancellation |
| `retry` | `ImportSession` | Retrying failed rows |
| `useImporter` | Importer definition | Seeing and using an importer |
| `export` | `ImportSession` | Downloading history reports or validation errors |
| `viewSensitiveImportFields` | `[ImportSession, ImportField]` | Revealing sensitive preview/history values |
| `editSensitiveImportFields` | `[ImportSession, ImportField]` | Correcting a sensitive staged value |
| `manageRetention` | `ImportSession` | Setting or releasing retention holds |
| `viewAny`, `create`, `view`, `update` | `ImportPreset::class` or `ImportPreset` | Listing, saving, reading, and updating mapping presets |

The default actor-owned scopes make sessions and presets private to their creator. Tenant-aware hosts should replace the execution context, session scope, and preset scope as described in [tenant context and data protection](docs/tenant-context.md).

## Operations and security

- Import files are private. The Hub does not expose server storage paths.
- Importer allowlists control target fields; spreadsheet content cannot choose a model, relationship, or database column.
- Declared sensitive values are encrypted in staged mapped/normalized payloads and in preview before/after storage. Source files and external parser temporary files are not encrypted by the package.
- Reconciliation and history read saved data, so they remain available if an importer is removed or upgraded.
- Progress is read from committed database state, not queue batches.
- Recovery is separate from queue retry limits. Run `smart-import:recover` periodically from your scheduler after confirming workers are running.
- Completion, failure, and cancellation notifications use a durable outbox. Schedule `smart-import:deliver-outbox`; delivery is at least once, and listeners must deduplicate using the stable event key.
- Retention commands are inspection-only. The package does not delete source files, row payloads, or audit history.
- Archived or rejected intake is read-only for imports: it cannot be staged, previewed, confirmed, retried, or executed. History, row details, progress, and exports remain available.
- Keep package tables and target models on one database connection. Execution rejects mixed connections before writing.

## Current limitations

- The package is pre-release: no tagged release, no Packagist publication, and no finalized license.
- The Filament adapter is optional and should be validated in a staging application; the package test suite covers the Laravel core.
- Duplicate groups are not automatically reduced to one row. There is no delete-row or keep-one-row action; correct keys or exclude invalid rows when the importer permits it.
- The Hub does not yet expose re-mapping/restaging after staging, staging cancellation, bulk row actions, duplicate comparison, template downloads, or preset rename/delete.
- Warning validation status is modelled in the schema and UI filters, but the built-in pipeline currently produces only valid and invalid rows.
- Retention is inspection-only; no destructive purge exists. Generic host record links are not provided.
- Verify queue locking and concurrency behavior on your production database and cache driver before relying on it at scale.

## Development

```bash
composer install
composer test
find src tests database config -name '*.php' -print0 | xargs -0 -n1 php -l
```

Regenerate the shipped Hub stylesheet after changing package Blade views:

```bash
npm install
npm run build:css
```

Commit the regenerated `resources/dist/smart-import.css` with the view change. Host applications consume that compiled file; they do not compile it themselves.

The package tests use Orchestra Testbench and synthetic models/files. Real queue-worker, concurrency, authorization, retention, and security-boundary tests are included. See the [compatibility record](docs/compatibility.md) for current versions and unverified combinations.

## Documentation

- [Filament Hub](docs/filament-hub.md)
- [Artisan workflow](docs/cli.md)
- [Importer definitions](docs/importer-definitions.md)
- [SFTP inbox](docs/sftp-intake.md)
- [Queue workers and recovery](docs/queue-workers.md)
- [Terminal notifications](docs/notifications.md)
- [Audit history and retention inspection](docs/audit-retention.md)
- [Tenant context and data protection](docs/tenant-context.md)
- [Performance measurements and processing limits](docs/performance.md)
- [Compatibility and verification](docs/compatibility.md)
- [Upgrade and deployment notes](docs/upgrading.md)
