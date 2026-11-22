# Filament Smart Import

A reusable Laravel import engine with an optional Filament 5 Import Hub. Import definitions, validation, staging, previews, writes, queue execution, and progress use one shared Laravel core; the Hub and other adapters call that same core.

> **Development status:** This package is still under development and is not a tagged production release. Use this build in a development or staging application first.

## Requirements

- PHP 8.2 or newer with Laravel 12; PHP 8.3 or newer with Laravel 13.
- Laravel 12 or 13.
- PhpSpreadsheet 5, installed as a package dependency.
- Filament 5 is optional and required only for the Filament Hub and Resource action.
- An asynchronous Laravel queue connection for background imports.

## Install from GitHub

The package is not published to Packagist yet, so tell Composer where its GitHub repository is before requiring it. Run these commands from your Laravel application's folder:

```bash
composer config repositories.filament-smart-import vcs https://github.com/ejoi8/filament-smart-import.git
composer require smart-import/laravel-smart-import:dev-main
```

The first command adds the public GitHub repository to your application's `composer.json`. The second installs the package's `main` branch. No GitHub token is needed for this public repository. Because `dev-main` is an in-development branch and not a tagged release, use it in development or staging while the package remains pre-release.

After Composer finishes, publish the package configuration and create its database tables:

```bash
php artisan vendor:publish --tag=smart-import-config
php artisan migrate
```

If you want to use the Filament Import Hub or Resource action, install Filament 5 in the application too, then follow [Enable the Filament Hub](#enable-the-filament-hub). The import engine itself can also be used without Filament.

## Install for local development

This workspace build is not a tagged release. Extract the package beside your Laravel application, then merge the following repository and package requirement into the application's existing `composer.json`. Adjust the path to match your folders; keep the app's other dependencies:

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

Then install the dependency:

```bash
composer update smart-import/laravel-smart-import
```

Laravel discovers the package service provider through Composer. Publish the config and run the package migrations:

```bash
php artisan vendor:publish --tag=smart-import-config
php artisan migrate
```

The package migration files are loaded by its service provider. Back up production data and review migrations before applying them to a production database.

## Define an importer

An importer is application code. It declares the target model, fields the import may write, a stable version, an optional matching key, and an explicit write policy.

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

Register only application-owned definitions in `config/smart-import.php`:

```php
'importers' => [
    App\Imports\ContactImportDefinition::class,
],
```

Increment the definition's `version()` when its fields, validation, transformations, or write behavior changes. See [importer definitions](docs/importer-definitions.md) for aliases, transforms, relationships, blank values, hooks, previews, and sensitive fields.

## Enable the Filament Hub

Install Filament 5 in the host application if it is not already installed. Register the plugin on the intended panel:

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

In `config/smart-import.php`, explicitly enable the panel and name the Gate ability that protects it:

```php
'filament' => [
    'panels' => [
        'admin' => 'accessSmartImportHub',
    ],
],
```

Define that ability and the package's operation abilities using your application's roles and tenant rules. Operations include `create`, `viewAny`, `view`, `update`, `queue`, `cancel`, `retry`, `useImporter`, and `export`. Sensitive preview fields can use the optional `viewSensitiveImportFields` ability. Authorization is checked by the shared services as well as the UI.

## Configure storage and queues

Use a private Laravel storage disk for uploaded and discovered files. The defaults use the `local` disk and `smart-import` root. Do not point import storage at a public web directory.

Imports run through Laravel's queue. Configure `smart-import.queue.connection` to an asynchronous queue connection or leave it unset to use `queue.default`. The queue name defaults to `imports`. For a database queue, the host application must have its queue tables installed. Start a worker, for example:

```bash
php artisan queue:work database --queue=imports --timeout=55 --tries=3
```

Replace `database` with the configured queue connection. Review [queue worker operation](docs/queue-workers.md) for timeouts, retry behavior, recovery, and Windows development commands.

## Run an import

The Filament Import Inbox is the primary workflow:

1. Upload a CSV, XLSX, or XLS file, or discover a file from the private SFTP inbox.
2. Assign an authorized importer, select the sheet and header row, and map source columns.
3. Stage and validate the rows, review the persisted change preview, then explicitly confirm the import.
4. Follow saved progress in **Active Imports** and inspect completed, failed, or cancelled work in **Import History**.

Uploads and SFTP discovery only register files. Discovery does not select an importer or start an import. See the [Hub guide](docs/filament-hub.md) and [SFTP intake guide](docs/sftp-intake.md) for the complete workflow and restricted-folder setup.

For scheduled or manual SFTP discovery, the package provides:

```bash
php artisan smart-import:discover --actor=123 --guard=web
```

The actor must resolve through the selected Laravel guard and pass the configured authorization. The package also provides `php artisan smart-import:recover --dry-run` to inspect stale work and `php artisan smart-import:recover` to recover eligible sessions. It does not install a host scheduler or start queue workers.

The same staged review flow is available from Artisan with explicit actor and guard IDs: `smart-import:stage`, `smart-import:preview`, and `smart-import:confirm`. Preview never writes target records; confirmation requires the current reviewed revision and an explicit `--confirm` flag. See the [Artisan workflow](docs/cli.md).

Completion and final-failure delivery uses a durable outbox. Run the configured queue worker and periodically schedule `php artisan smart-import:deliver-outbox`; see [terminal notifications](docs/notifications.md) for delivery guarantees and recipient setup.

Audit history is available on authorized Import History details. Retention is inspection-only; review candidates with `php artisan smart-import:retention-dry-run`. See [audit history and retention inspection](docs/audit-retention.md).

## Security notes

- Importers explicitly allowlist target fields; spreadsheet content cannot choose a model or database column.
- Import files are stored privately, and the Hub does not expose server storage paths.
- Fields declared sensitive are encrypted in new staged cell payloads; sensitive before/after values are encrypted in preview storage and require the `viewSensitiveImportFields` Gate to reveal. See [tenant context and data protection](docs/tenant-context.md) for tenant workers, legacy rows, keys, and storage limits.
- History exports require the `export` Gate. Formula-like CSV values are neutralized, and sensitive cell values are withheld unless separately authorized.
- Keep the package tables and imported target models on the same database connection so row writes and import counters can commit atomically.

## Development and known gaps

Run the package test suite after installing the development dependencies:

```bash
composer install
composer test
```

The package tests use Orchestra Testbench and synthetic models/files. The separate Laravel + Filament applications used for panel integration checks are local fixtures and are not included in this Composer package. See the [compatibility record](docs/compatibility.md) for exact local versions, current CI coverage, and unverified minimum combinations.

The repository has no `LICENSE` file and Composer's license metadata is unset. The owner must choose the license before public distribution. No stable version has been tagged.

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
