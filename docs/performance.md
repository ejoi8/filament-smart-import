# Performance measurements and processing limits

These measurements guide implementation decisions; they are not throughput promises. Times depend on hardware, PHP extensions, filesystem, database, and queue-worker settings. The figures below were measured on PHP 8.4.23 with synthetic files. Reader and staging timings are in-process measurements; the separate end-to-end result used Laravel's database queue and real `queue:work --once` invocations.

## Reproduce the package measurements

Run from the package root:

```sh
vendor/bin/phpunit benchmarks/SmartImportPerformanceTest.php
```

The synthetic fixtures cover a narrow 10,000-data-row CSV, a 5,000-row/64-column CSV, a duplicate-heavy 10,000-row staging run, 1,000-row/12-column XLSX and XLS files, the 100,000-source-row boundary, and an approximately 10 MiB CSV with 160 columns and two long data rows. The CSV row counts reported by the reader include the header when one is present.

The package benchmark directly calls the reader and staging service. Its queue setting is `smart-import-testing`, but `Queue::fake()` is active; these parser/staging timings do not represent worker latency. Peak-memory figures are PHP `memory_get_peak_usage(false)` deltas, not operating-system resident memory.

## Observed results

| Scenario | Input | Time | Peak PHP memory delta | Database queries |
|---|---:|---:|---:|---:|
| CSV reader, 10,000 data rows, 2 columns | 377,799 bytes | 0.095 s | 7.9 MB | 0 |
| CSV reader, 5,000 data rows, 64 columns | 2,874,714 bytes | 0.442 s | 7.9 MB | 0 |
| Duplicate-heavy CSV staging, 10,000 rows | 358,905 bytes | 12.422 s | 8.3 MB | 20,535 |
| XLSX reader, 1,000 rows, 12 columns | 76,926 bytes | 0.648 s | 2.9 MB | 0 |
| XLS reader, 1,000 rows, 12 columns | 479,744 bytes | 0.427 s | 8.3 MB | 0 |
| CSV reader, 99,999 data rows / 100,000 source rows | 3,977,759 bytes | 0.741 s | 7.9 MB | 0 |
| CSV reader, approximately 10 MiB, 160 columns, 2 long rows | 10,241,812 bytes | 1.711 s | 20.8 MB | 0 |

The duplicate-heavy staging measurement marks all 10,000 records invalid because they share the same configured key. Its query profile included 10,000 row-specific duplicate-error updates, 10,085 session reads, 200 grouped inserts, 41 duplicate-key lookups, and 40 target lookups. Row-specific errors and validation counters remain persisted; this is the measured high-query workload to revisit if duplicate-heavy staging becomes a common production case.

An isolated Laravel 12.72 / SQLite application also ran the actual queued workflow for 10,000 unique contacts: browser intake, staging, asynchronous preview, confirmation, execution, and notification delivery. Using the `database` queue, the `imports` queue, 250-row reader/execution chunks, and a real `queue:work database --queue=imports --once --sleep=0 --tries=1` call per job, it completed 42 worker jobs in 44.272 seconds. PHP peak-memory delta was 9.19 MB; the run recorded 111,073 database queries and created all 10,000 contacts. This is one local SQLite measurement, not a cross-database service-level target.

## Changes justified by measurement

- CSV data rows now stream through `fgetcsv` after PhpSpreadsheet has selected the delimiter/encoding and validated worksheet dimensions. A bounded 64 KiB sample is used for delimiter inference instead of scanning a long physical line. This preserves quoted newlines, source row positions, blank-row behavior, supported encodings, and leading-zero string values.
- Before streaming, the 10,000-row narrow CSV took 1.53–1.70 seconds, the 5,000-row wide CSV took 8.04–9.27 seconds, and the 100,000-source-row case took 137.79 seconds. A maximum-size CSV briefly used about 303 MB of extra PHP memory in delimiter inference; the bounded sample brought it to about 20.8 MB.
- Duplicate-heavy 10,000-row staging fell from 14.46–14.95 seconds and 30,335 queries to 12.422 seconds and 20,535 queries after new staged rows were inserted in groups of 50 within the existing locked batch transaction.
- Failed-row retries load and reset rows in locked batches of 250. A regression with 501 failed rows verifies only three row-update statements are needed, then confirms the dispatcher still sends all rows in six bounded execution jobs.
- The latest-audit query plan on SQLite initially used the `(import_id, created_at)` index and an additional temporary sort for `ORDER BY id DESC`. The additive `(import_id, id)` index removes that sort. The same representative plan uses the existing match-key and execution-status indexes for duplicate lookup and retry batches. Query plans vary by database driver; no claim is made that SQLite proves another driver's optimizer behavior.

## Format and resource limits

Defaults are configurable by the host in `config/smart-import.php`:

- Maximum uploaded file size: 10 MiB.
- Maximum source rows: 100,000.
- Maximum columns: 256.
- Maximum sheets: 20.
- Maximum text length in a cell: 32,767 characters.
- Maximum expanded XLSX archive size: 32 MiB.
- Reader/staging batch size: 250 rows; maximum accepted batch size is validated by the reader.
- Execution job size: 100 row IDs by default, configurable up to 1,000.

CSV is streamed after its bounded sample and dimension check. XLSX/XLS remain on PhpSpreadsheet with row read filters and configured chunks. This reduces loaded cells, but the XLSX/XLS parser may still parse workbook structures for each chunk; these formats are **not claimed to have constant-memory parsing**. The measured 1,000-row workbooks are representative checks, not a proof for every workbook layout. Expanded archive size, dimensions, file size, row/column limits, and cell text length are enforced, but hosts should still use worker memory/time limits appropriate to their largest allowed files.

Formula cells are read as their stored literal formula text; formulas are not evaluated. The package accepts `.csv`, `.xlsx`, and `.xls`; macro-enabled `.xlsm` is outside the supported extension set. Generic ZIP content is not accepted as XLSX. See the security and reader feature tests for malformed-content, expanded-archive, symlink, source-checksum, formula-literal, and quota coverage.

## Retention and audit volume

Audit entries and session summaries are retained; the package has no purge or archive operation. Audit event fields/metadata are bounded, per-import history reads are capped, and retention inspection is read-only. Total audit-table growth therefore remains dependent on host import volume and requires an owner-approved retention/archive policy before a destructive lifecycle is added. See [audit history and retention inspection](audit-retention.md).
