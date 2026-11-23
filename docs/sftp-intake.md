# Private SFTP inbox intake

Smart Import does not run an SFTP server or manage SFTP accounts. The host application or its administrator owns the SFTP service, restricted account, credentials, folder permissions, and scheduling.

## Storage setup

Configure `smart-import.storage.disk` to a private Laravel local disk and `smart-import.storage.root` to a relative directory on that disk. The package scans only the direct `inbox/` child and creates sibling `processing/`, `quarantine/`, `rejected/`, and `archive/` directories. Do not point these directories at `public/` or `storage/app/public`.

The SFTP account should be restricted to the inbox directory and must not be able to read the application source, `.env`, or the processing directory. The inbox and processing directories must be on the same filesystem so a completed file can be claimed with an atomic rename.

## Upload protocol

The uploader should use a safe filename and follow this sequence:

1. Upload to a temporary filename ending in `.part`, such as `contacts-2026-11.csv.part`.
2. Flush and close the file after the upload completes.
3. Atomically rename it in the same inbox directory to `.csv`, `.xlsx`, or `.xls`.

Discovery ignores hidden names, `.part` files, and subdirectories. It checks recognized files for regular-file status, path containment, stability, size, MIME type, and matching PhpSpreadsheet reader. Valid files move into `processing/` and are checked again for size and SHA-256 after the move. Unsupported, oversized, unreadable, or unsafe inbox entries are moved to `quarantine/` and recorded as rejected sessions for operator review. Symlinks are moved as links and never followed. A rejected file with no verified checksum (for example, a symlink or oversized file) has a nullable hash; the package does not invent one.

## Calling discovery

The host may call the shared Laravel service from a scheduled command or an authorized operator action:

```php
use SmartImport\Services\FileIntakeService;

$sessions = app(FileIntakeService::class)->discoverInbox($actor);
```

The package provides the same operation through Artisan. It requires an actor ID that Laravel can resolve through the selected authentication guard:

```sh
php artisan smart-import:discover --actor=123 --guard=web
```

The Hub Inbox also provides a **Discover files** action for the currently authenticated operator. Both entry points call `FileIntakeService::discoverInbox()`.

`$actor` must be an authenticated host actor with the `create` Gate ability for `SmartImport\Models\ImportSession`. When a trusted tenant ID is passed, the actor must also pass `createForTenant`. The configured Laravel cache store must support atomic locks; use a lock-capable shared store when web and scheduler processes run on multiple workers or hosts.

The method returns only newly registered records. It is safe to call again: each accepted record retains the canonical inbox path, while `claimed_storage_path` points to its private processing or quarantine copy. Discovery leaves importer assignment and both staging and execution untouched. Identical accepted content under a different path is retained and linked through `possible_duplicate_of`; the Hub displays it for review.

An operator can reject an unstarted or review-ready session with a required reason, moving its retained private source to `rejected/`, or archive an eligible inactive session into `archive/`. These actions preserve the canonical source path, hash, and size; they never delete the source. Active staging/import work cannot be rejected or archived. Rejected and archived sessions are read-only for import purposes: they cannot be assigned, staged, previewed, confirmed, retried, or executed. Progress, history, row details, and exports remain available. Archiving does not support an unarchive transition; create a new intake to import the file again.

To schedule periodic discovery, the host application can register the command with its Laravel scheduler and an explicit service-user ID:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('smart-import:discover --actor=123 --guard=web')->everyMinute();
```

Quarantine and lifecycle log entries contain sanitized filenames/reasons and no storage paths or spreadsheet cell values. Retention cleanup is not automatic. Use the inspection-only [`smart-import:retention-dry-run`](audit-retention.md) report to review eligible objects and protected sessions; the package never removes them.
