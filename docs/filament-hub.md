# Filament Import Hub

The Filament adapter is optional. Install Filament 5 in the host application, publish the package config, register the plugin on the intended panel, and explicitly authorize that panel.

```bash
php artisan vendor:publish --tag=smart-import-config
```

Register the plugin in the host panel provider:

```php
use Filament\Panel;
use Filament\PanelProvider;
use SmartImport\Filament\SmartImportPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->plugins([SmartImportPlugin::make()]);
    }
}
```

In `config/smart-import.php`, register only the panels that should expose the Hub and map each panel to an application Gate ability:

```php
'filament' => [
    'panels' => [
        'admin' => 'accessSmartImportHub',
    ],
],
```

The application defines `accessSmartImportHub` and the package operation abilities (`create`, `viewAny`, `view`, `update`, `queue`, `cancel`, `retry`, `useImporter`, and `export`) using its own user, role, and tenant rules. Each operation is checked server-side. `viewSensitiveImportFields` is an optional field-level Gate for revealing sensitive preview values; it receives the import session and field. Keep the configured importer list explicit; importer options are filtered by `useImporter` and assignment is checked again by the shared coordinator.

The **Import Inbox** is the primary Hub destination. A browser upload uses the same `FileIntakeService` as other intake adapters, creates a private unassigned inbox record, and does not start staging. An authorized operator assigns an importer, selects a worksheet and header row, reviews the preview and suggested/manual column mappings, and explicitly starts validation staging. Staging validates rows for review and does not write target records.

The Hub table intentionally omits server storage paths. It shows the original filename, source, type, size, checksum, importer, intake/import status, and received time. The mapping page rechecks session scope, `view`, `update`, panel access, and importer use on every server operation.

## Background execution queue

After the operator reviews the persisted database preview and confirms it, the package freezes the confirmed revision, execution row count, attempt number, and queued timestamp. It then dispatches bounded `ExecuteImportChunkJob` jobs after the database transaction commits. Each job carries only the import ID, attempt ID, confirmed revision, and a bounded list of staged row IDs; it does not serialize an Eloquent model, file, or row payload. The first chunk moves the session to `importing`; the last committed row outcome moves it to `completed` and emits one `SmartImport\Events\ImportFinished` event after commit. A completed import can include failed rows; inspect its counters to distinguish row failures from worker failure.

Configure `smart-import.queue.connection` to an asynchronous Laravel queue connection, or leave it empty to use `queue.default`. The default queue name is `imports`; `smart-import.execution.chunk_size` defaults to 100 and must be between 1 and 1000. Chunk jobs default to three tries and backoff delays of 10, 30, and 60 seconds; `job_tries` accepts 1–10 and `job_backoff_seconds` accepts up to eight integer delays from 0 to 86400 seconds. A production `sync` driver is rejected during confirmation because it would run the import inside the web request. Use a queue worker to process the selected connection and queue; see [queue worker operation](queue-workers.md) for Linux/VPS and Windows commands, timeout guidance and recovery steps.

Confirmation returns with a durable queued session even if no browser remains open. Queue dispatch failures move the session to `failed` with a safe configuration message; they do not silently leave a queued session with no jobs.

## Persistent progress

The **Active Imports** page lists all actor-visible sessions in staging, queued, importing, or cancelling state, including sessions that came from browser upload, SFTP discovery, CLI, or a Resource shortcut using the shared session workflow. It reads saved counters and last activity, and polls every two seconds while an active session remains. Each session opens the same progress detail page; the listing filters by the configured actor/tenant scope and per-session `view` Gate.

Sessions with an import status also have a **View progress** action in the Inbox. The detail page reads the authorized session and committed row outcomes from the database at mount and on each two-second Livewire poll while staging, queued, importing, or cancelling. Refreshing the browser or opening the page in another tab therefore restores the latest percentage, outcome counts, staging counts, attempt, duration, last activity, and recent row errors. Polling ends at a terminal state. The Active Imports and detail pages both link back to the Inbox; navigation does not request cancellation, so leaving either page has no effect on queue work.

Queued sessions can be cancelled immediately. An importing session records a cancellation request and finishes at a row transaction boundary; committed rows remain committed and are reported, while rows that have not committed stay pending. Already-enqueued chunk messages may still be consumed by the configured worker, but they check the persisted session state and do not write after cancellation. Only actors allowed by the `cancel` Gate see the cancellation action, and the core checks that Gate again when the request is submitted.

A completed attempt with failed rows can start a **Retry failed rows** attempt. The package preserves the prior attempt's status and counters in `attempt_history`, appends a new attempt ID, resets only failed rows to pending, and dispatches only those row IDs. Successful rows keep their existing outcomes. Retries require the `retry` Gate and `useImporter`; the importer definition/version and confirmed mapping revision must still match. The retry uses configurable bounded queue tries and backoff (`smart-import.execution.job_tries` and `job_backoff_seconds`).

## Import history

The **Import History** page lists completed, failed, and cancelled sessions within the configured actor/tenant scope. Operators can filter by status, source, importer, target model, initiating actor, tenant, and received date. Selecting a session opens its authorized summary with file metadata, importer and sheet information, mapping revisions, validation counts, committed outcome counts, duration, and recorded attempt summaries. The detail route checks the session scope and `view` Gate again.

The summary links to paginated row results, which can be filtered by validation and execution outcome. A row detail shows the planned change, recorded target references for the preview and each committed attempt, and the persisted before/after field diff. This history uses saved row data directly, so it remains available if an importer is later removed or upgraded. The optional `viewSensitiveImportFields` Gate controls each sensitive field; values stay encrypted in storage and appear as `[redacted]` when access is absent or decryption fails. Error messages are generic, and the pages omit raw spreadsheet values, stored exception text, mappings, ciphertext, and storage paths. Row detail verifies that the row belongs to the authorized session; it does not load or link to target records.

The history summary provides CSV downloads for the import summary and failed rows. Both actions require the session's `export` Gate and recheck authorization in the shared export service. The summary includes safe file/import metadata, persisted counters, status, and timing. The failed-row report includes validation and execution failures with generic reasons; it includes a source value only when its field is known and either non-sensitive or allowed by `viewSensitiveImportFields`. CSV cells that could be interpreted as spreadsheet formulas are prefixed safely.

## Stale work recovery

Run `php artisan smart-import:recover` periodically from a scheduler or operations process after checking that queue workers are running. `--dry-run` reports stale sessions without changing them; `--limit=100` bounds each scan and accepts values from 1 to 500. The package does not install a scheduler entry or start workers for the host application.

`smart-import.recovery.stale_after_seconds` defaults to 900 and accepts 1–604800 seconds. `smart-import.recovery.max_recoveries` defaults to 3 and accepts 1–10 recoveries per staging or execution attempt. Recovery uses the persisted heartbeat, with `updated_at` as a fallback for older sessions. Staging restarts from the source file and skips already-committed rows only when their source fingerprints still match. Execution keeps the same attempt and confirmed revision, validates saved counters against pending rows, and queues only pending row IDs. Row transaction locks make duplicate queue messages safe. A stale cancellation is finalized from committed outcomes, and a crash after the final row commit is reconciled as completed. Sessions with inconsistent counters or an exhausted recovery limit are marked failed with a safe summary for operator review.

The recovery count resets when an operator begins a fresh staging run or a new failed-row retry attempt. Queue job retry/backoff and stale-session recovery are separate limits: failed chunk jobs first exhaust Laravel's configured job tries, then the recovery command may re-dispatch their still-pending rows.

Progress lookup repeats the configured actor/tenant session scope and `view` Gate check. The page does not return staged cell values, storage paths, or persisted error text; recent errors contain source row, internal error code, and a fixed generic message. A user can return to the Inbox at any time without affecting queue work.

The isolated Laravel 12 + Filament 5 test fixture exercises this with Laravel's real database queue and `queue:work --once`: it stages an uploaded file, confirms it, commits one execution chunk, recovers a stale session, opens a fresh progress page at 50%, then completes the work with no browser component mounted. It also exhausts a real chunk job, recovers the failed session, and verifies successful completion after the importer becomes available again.
