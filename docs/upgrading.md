# Upgrade and deployment notes

This package has not released a stable tagged version. Review the current branch and package migrations in a disposable application before upgrading a host. Take a database and private-storage backup using the host's normal procedures before production changes.

## Configuration

If the host published `config/smart-import.php`, compare it with the current package configuration and merge any new keys while preserving host-owned importer, tenant, authorization, storage, queue, encryption, notification, and panel settings. Do not publish with `--force`; that would replace the host file.

Keep `SMART_IMPORT_INDEX_KEY` stable while retained sessions or rows use version 2 signatures. Changing the key prevents existing indexed signatures from matching and resumable work fails closed. If Laravel's `APP_KEY` is rotated, retain the prior decryption key for encrypted staged payloads and previews according to Laravel's encryption-key rotation support.

Tenant-aware hosts must configure their `ImportExecutionContext` in web and worker processes. The default allows tenant-free work and rejects tenant-bound jobs when no host context is installed.

## Database and workers

The current package migrations are additive: they add durable terminal outbox/notification tables, audit history and retention-hold columns, and an audit-history index. Review the migration files against the installed package version before running `php artisan migrate --force`. Do not roll back package migrations in a database containing Smart Import data; this package does not provide an automatic data purge.

Deploy the same package version and configuration to web requests, queue workers, and scheduled Artisan processes. Ensure the configured asynchronous queue and its tables/driver are ready before operators confirm imports. Run `smart-import:recover --dry-run` to inspect stale work, then schedule recovery only after reviewing its output. Run `smart-import:deliver-outbox` periodically so durable terminal notifications are delivered after queue dispatch gaps.

## Existing imports and payloads

The package does not automatically rewrite or delete old staged rows, sources, sessions, previews, or audit entries. Legacy unwrapped staged cell payloads and version 1 index hashes remain readable; new sensitive payloads and indexed signatures use versioned envelopes/signatures. Do not edit a confirmed import in place to refresh its preview or key identity. If the application reports that an old confirmation snapshot cannot be checked safely, leave its evidence intact and create a new reviewable import from the retained source after the host confirms it is still valid.

## First installation

For a new host, follow the current [README installation guide](../README.md), publish package configuration once without `--force`, review the migrations, and run them through the host's normal deployment process. Configure importer definitions and authorization before enabling a Filament panel or granting import permissions.
