# Artisan workflow

Smart Import's Artisan commands use the same registry, authorization checks, staging, preview, and confirmation services as the Filament Hub. The CLI does not select a model or write directly to a target table.

Every session operation requires an explicit `--actor` ID and `--guard`. Laravel resolves the actor through that guard's configured user provider. The actor's host-application scope determines which sessions and tenants are visible; there is no free-form tenant override. Queue jobs persist session and revision IDs, not the authenticated actor.

## Stage and validate a session

Start with a session already registered by an authorized browser upload or private SFTP discovery. Choose an importer, worksheet index, header row, and a complete JSON mapping from one-based source column indexes to importer field names or `null` to ignore a column:

```bash
php artisan smart-import:stage SESSION_ID \
  --actor=123 --guard=web --importer=contacts \
  --sheet=0 --header-row=1 \
  --mapping='{"1":"name","2":"email","3":null}'
```

For a file without a header row, use `--header-row=none`. Worksheet indexes are zero-based; header rows are one-based.

Mapping presets saved in the Hub can also be used. They are checked against the selected importer version, sheet, header values and source column indexes, and reader settings. Incompatibility stops staging:

```bash
php artisan smart-import:stage SESSION_ID \
  --actor=123 --guard=web --importer=contacts \
  --sheet=0 --header-row=1 --preset=PRESET_ID
```

Optional settings are supplied as a bounded JSON object. The same delimiter and encoding are used to read the header and stage the rows:

```bash
php artisan smart-import:stage SESSION_ID \
  --actor=123 --guard=web --importer=contacts \
  --sheet=0 --header-row=1 \
  --mapping='{"1":"name","2":"email"}' \
  --options='{"csv_delimiter":";","csv_encoding":"UTF-8","importer_options":{"source_label":"partner"}}'
```

Importer options are accepted only when the registered importer declares them. Values are validated again before queueing and are frozen in the session revision. Importer hooks can read them from `ImportRowContext::$importerOptions` while processing a row.

Staging validates data for review. It does not write target records.

## Generate a read-only preview

Use the current staging revision shown by the Hub or returned by `smart-import:stage`:

```bash
php artisan smart-import:preview SESSION_ID --actor=123 --guard=web --revision=2
```

The command writes a planned diff to Smart Import's review records only. It never creates, updates, or deletes target records. Larger previews may be queued. Run the configured queue worker, then repeat the command if you want a fresh response; persisted preview status is also available in the Hub.

## Explicitly confirm the reviewed revision

After reviewing a complete preview and resolving validation issues, confirm the same current revision explicitly:

```bash
php artisan smart-import:confirm SESSION_ID \
  --actor=123 --guard=web --revision=2 --confirm
```

The command rejects stale revisions, incomplete previews, unauthorized actors, and queue configurations that cannot run asynchronously. Without `--confirm`, it returns without queueing writes. Target changes are made later by the configured Laravel queue worker, using the frozen reviewed revision.

## Output and exit codes

Commands return one JSON object on standard output so scripts can read the outcome. Exit codes are:

- `0` — accepted or completed successfully; staging/execution may still be queued.
- `1` — an operational failure prevented the command.
- `2` — invalid, stale, incomplete, or unconfirmed input.
- `3` — the selected actor is not authorized.

Run `php artisan smart-import:stage --help`, `php artisan smart-import:preview --help`, or `php artisan smart-import:confirm --help` for exact options.

## Inspect retention pages

The retention command is read-only. If the JSON response has `has_more: true`, pass its `next_cursor` back with the same age filter. For example, with `jq` installed:

```bash
report="$(php artisan smart-import:retention-dry-run --older-than=365 --limit=100)"
cursor="$(printf '%s' "$report" | jq -r '.next_cursor // empty')"
if [ -n "$cursor" ]; then
  php artisan smart-import:retention-dry-run --older-than=365 --limit=100 --cursor="$cursor"
fi
```

The cursor preserves the original cutoff and `(created_at, id)` scan boundary. It is encrypted with the Laravel application key, expires after seven days, and is invalid after key rotation or if the age filter changes. Start a new dry run when a cursor expires or fails validation. See [audit and retention](audit-retention.md) for protection rules and scan consistency.
