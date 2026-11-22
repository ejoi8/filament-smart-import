# Audit history and retention inspection

Smart Import writes a bounded audit entry in the same database transaction as each supported operation. The allowlisted events cover intake registration and lifecycle changes, importer assignment, mapping/settings changes, staging, row correction, preview, confirmation, cancellation, completion/failure, retry, recovery, notification delivery failure, and retention holds.

Audit metadata is restricted by event and field. It contains safe status codes, row numbers, revisions, counters, and actor/tenant identifiers. It does not contain spreadsheet cell values, raw exception text, source storage paths, or arbitrary caller-provided metadata. Audit history has its own indexed table and deliberately has no foreign key to the import session, so deleting or archiving session records in a separately approved retention process need not erase the audit trail.

The Import History detail page shows the latest authorized audit events for that session. The audit service repeats the session scope and `view` authorization check before returning entries. Hosts should apply their tenant policy to the same session scope used for other Smart Import history.

## Inspect retention candidates

Retention is inspection-only. The package does not remove source files, staged rows, sessions, summaries, or audit entries. Run a bounded report:

```sh
php artisan smart-import:retention-dry-run --older-than=365 --limit=100
```

`--older-than` accepts 1–3650 days and `--limit` accepts 1–500 imports. The JSON report lists exact eligible object types (`source_file` and `staged_rows`) by session ID, plus protected session IDs and reason codes. It never emits a storage path. `has_more` indicates that another bounded page remains to inspect. If true, the report includes an opaque `next_cursor`; pass it to the next call to inspect beyond the current page:

```sh
php artisan smart-import:retention-dry-run --older-than=365 --limit=100 --cursor='OPAQUE_NEXT_CURSOR'
```

The first page fixes both its age cutoff and its upper `(created_at, id)` boundary. Continuation uses keyset pagination in that order, so tied timestamps are visited once and imports added after the scan begins do not extend it. You may change the page limit on later calls. The cursor is encrypted and authenticated with Laravel's application key, contains only pagination state, and expires after seven days. Key rotation, expiry, a changed `--older-than` value, or a damaged cursor invalidates it; start a new scan to recover. Do not log or publish cursor values as they contain opaque operational metadata.

The scan boundary is stable, but each page rechecks current protection state. A hold, retry, recovery, or notification delivery change between pages may therefore change that session's reported eligibility. The cursor grants no permission for deletion and the command remains read-only. Session summaries and audit history are always reported as retained.

The eligibility check protects active or unfinished work, failed sessions that require recovery/review, unprocessed rows, retryable failed rows, unsettled or leased terminal notifications, missing source files, and sessions with a manual retention hold. The report reads database state and checks private storage object existence; it does not mutate either. The `(created_at, id)` index supports the bounded ordered scan.

## Place and release a hold

The host may place a hold through the service after applying its own legal/retention workflow. The actor must be in the session scope and pass both `view` and `manageRetention` authorization. The reason is required, bounded, and stored with the session; setting and release operations are audited atomically.

```php
use SmartImport\Services\ImportRetentionService;

$retention = app(ImportRetentionService::class);
$retention->placeHold($sessionId, $actor, 'Legal review case 1234');

// Release only after the host's retention/legal review authorizes it.
$retention->releaseHold($sessionId, $actor);
```

There is intentionally no deletion command or destructive retention API. Any future purge implementation requires a separate design, explicit authorization, dependency checks, and approval before it runs.
