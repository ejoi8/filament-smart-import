# Compatibility and verification

## Supported package constraints

`composer.json` allows PHP `^8.2`, Laravel components `^12.0 || ^13.0`, and PhpSpreadsheet `^5.0`. Filament is optional at runtime; install Filament `^5.0` only when using the Hub or Resource action. In practice Laravel 13 requires PHP 8.3 or newer, so the package's PHP 8.2 floor applies to Laravel 12 combinations.

The package engine is Laravel-only and does not load Filament classes unless the Filament adapter is used. The integration runs on Filament 5 and Livewire 4.

## Locally verified combinations

| PHP | Laravel | Testbench / Filament | Evidence |
|---:|---|---|---|
| 8.4.23 | 12.72.0 | Testbench 10.12.0 / Filament 5.11.3 | Package suite on Testbench 10: 278 tests / 1,664 assertions; isolated Filament application suite: 44 / 516. One MySQL/MariaDB-only row-lock test is skipped on SQLite. |
| 8.4.23 | 13.42.0 | Testbench 11.3.0 | Package suite on Testbench 11: 278 tests / 1,664 assertions; one MySQL/MariaDB-only row-lock test is skipped on SQLite. |
| 8.4.23 | 13.43.0 | Filament 5.11.4 | Fresh isolated application: dependency install, package auto-discovery, config publish, all package migrations, Filament panel boot and Hub route registration; integration suite: 44 tests / 516 assertions. |

The Laravel 12 and Laravel 13 applications use separate isolated test fixtures. The fixtures are not shipped in the Composer package. The package suite is independently runnable from a clean checkout with the commands in the README.

The standalone reader/staging benchmark is `vendor/bin/phpunit benchmarks/SmartImportPerformanceTest.php`. The package suite also includes `RealQueueWorkflowTest`, which calls `generateOrQueue()` at the configured threshold and runs staging, queued preview, confirmed row execution, and terminal outbox delivery through Laravel's database queue worker. It uses the default Eloquent actor resolver and verifies no target write before confirmation, notification actor scoping, safe payload contents, and duplicate-delivery behavior.

## CI coverage

`.github/workflows/tests.yml` defines PHP 8.2 / Laravel 12 / SQLite, PHP 8.4 / Laravel 12 / MariaDB, PHP 8.3 / Laravel 13 / SQLite, and PHP 8.4 / Laravel 13 / MariaDB. Each job resolves the matching Testbench major, validates Composer/platform requirements, runs the package suite (including the real queue worker test), and lints PHP files.

The workflow is checked into the local worktree but has not run on GitHub because no commit or push was authorized. A green local SQLite suite does not prove MySQL/MariaDB locking behavior; the MariaDB workflow job must pass before release.

## Known compatibility boundaries

- Laravel 12 on PHP 8.2 and Laravel 13 on PHP 8.3 are dependency-constraint targets in CI; this machine runs PHP 8.4.23, so those minimum PHP combinations have not been executed locally.
- Supported databases are not generalized by the SQLite test suite. CI's MariaDB job is intended to exercise migrations and row-lock paths on that engine.
- Filament integration was exercised on Laravel 12 and 13, but the external app fixtures are not part of the distributable package or current GitHub workflow.
- CSV parsing streams records. XLSX/XLS use PhpSpreadsheet read filters and bounded row chunks; constant-memory workbook parsing is not claimed. See [performance measurements](performance.md).
- `.xlsm` macro-enabled workbooks are rejected. Supported workbook extensions are `.xlsx` and `.xls`.

## Package metadata still requiring owner action

The package name is `smart-import/laravel-smart-import`. Composer currently has no `license` value and the repository has no `LICENSE` file. The project owner must select and confirm the license before public distribution; this build does not infer or add one.
