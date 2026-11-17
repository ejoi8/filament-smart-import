# Queue workers

Smart Import sends staging and execution jobs to Laravel's queue. A separate worker process must be running for imports to progress. The package does not install a worker service, choose a process monitor, or schedule recovery for the host application.

## Configure the queue

In the host application's `config/smart-import.php`, use the name of an asynchronous connection declared in `config/queue.php`. Leave the connection `null` to use Laravel's `queue.default`. The package queue name defaults to `imports`:

```php
'queue' => [
    'connection' => env('SMART_IMPORT_QUEUE_CONNECTION'),
    'name' => env('SMART_IMPORT_QUEUE', 'imports'),
],
```

The `sync` driver runs work in the request and is rejected for real confirmation outside tests. The host must configure and operate the queue backend. For Laravel's database queue, the host must have run the queue-table migration and created the `jobs` table. If it wants Laravel's failed-job records, it must also have the `failed_jobs` table and a configured failed-job driver.

## Linux or VPS

From the host application's root, run a worker in the foreground to check the connection and queue name:

```sh
cd /path/to/laravel-app
php artisan queue:work database --queue=imports --sleep=3 --timeout=55 --tries=3 --max-time=3600
```

Replace `database` with the connection configured for Smart Import. Keep the worker running under the process monitor chosen for the application. A production worker should be restarted if it exits; this guide does not prescribe a particular monitor or provide host-level service configuration.

Smart Import execution jobs carry their own bounded `tries` and `backoff` values from `smart-import.execution.job_tries` and `job_backoff_seconds`. Laravel gives a job's own `tries` value precedence over the worker's `--tries` fallback. The `--sleep` option controls how long an idle worker waits before polling again. `--max-time` makes a long-lived worker exit periodically so the process monitor can start a fresh one.

For one-job smoke checks, use `--once`. For a finite worker that exits after draining currently available work, use `--stop-when-empty`:

```sh
php artisan queue:work database --queue=imports --once
php artisan queue:work database --queue=imports --stop-when-empty
```

### Timeout and visibility

Choose `--timeout` to exceed the normal time required for one execution chunk. Set the queue connection's `retry_after` above the worker timeout, with a safety margin. Laravel may make a reserved job available again after `retry_after`; if that happens before the original worker stops, the same job can run twice. Smart Import locks and commits each row outcome so duplicate delivery does not double-count or repeat a committed target write, but correct timeout settings avoid needless overlap. On SQS, configure the queue visibility timeout for the same relationship.

Chunk size and worker limits are separate settings. `smart-import.execution.chunk_size` defaults to 100 rows and accepts 1–1000. Reduce it if a chunk takes too long for the worker timeout; increase the worker timeout and queue retry interval together only when the host's normal workload requires it.

### Deployments and interruptions

Queue workers are long-lived and do not load new application code until restarted. After a deployment, Laravel's `php artisan queue:restart` asks workers to finish their current job and exit. Laravel stores this signal in the configured cache, so the cache must be available to both the command and workers. The selected process monitor should start the worker again after it exits.

Avoid forcibly stopping workers during a chunk when a graceful restart is possible. If a process is killed, a reserved queue message may become available later and row locks keep redelivery safe. Once workers are healthy, preview stale sessions first and then run recovery:

```sh
php artisan smart-import:recover --dry-run --limit=100
php artisan smart-import:recover --limit=100
```

The recovery command is system-wide and can requeue work for any actor; restrict who can run it. It does not start workers. Run it periodically from the host's scheduler or operations process. Tune `smart-import.recovery.stale_after_seconds` to exceed ordinary worker silence and use `smart-import.recovery.max_recoveries` to bound recovery cycles. See [Filament Hub progress and recovery](filament-hub.md#stale-work-recovery) for the durable session rules.

Do not use `php artisan queue:retry` as the Smart Import session recovery action. An exhausted chunk moves its import session to `failed`; the shared recovery service must validate its persisted attempt/counters and return it to `queued` before sending pending rows again. Use `smart-import:recover` after addressing the worker or importer problem.

## Windows development

In PowerShell, change to the host application and start the same Laravel worker in its own terminal window:

```powershell
Set-Location 'C:\path\to\laravel-app'
php artisan queue:work database --queue=imports --sleep=1 --timeout=55 --tries=3
```

Replace `database` with the configured asynchronous connection. Leave this terminal open while testing imports. Use a second PowerShell window to run migrations, the Filament application, or an explicit recovery dry run. Stop the worker with `Ctrl+C`; after changing application code, start it again so the new process loads the new code. For a one-job check, append `--once`.

If `php` is not on `PATH`, use the full path to the PHP executable. The worker command is an interactive development example; Windows production hosting should use the process monitoring approach selected for that host.

## Troubleshooting

| What you see | Check |
| --- | --- |
| The session stays queued | Confirm the worker uses the same connection and `imports` queue configured by Smart Import. Check that the selected queue connection is not `sync`. |
| A worker runs but imports do not move | Check Laravel's application logs, failed-job storage, and the worker's queue name. Confirm it is running in the intended application environment. |
| A session is failed with a queue retry summary | Correct the worker/importer problem, then use `smart-import:recover --dry-run` and `smart-import:recover`. Review the persisted session summary if recovery refuses unsafe counters or a reached recovery limit. |
| A session remains stale after a worker restart | Run `smart-import:recover` after the stale timeout. Recovery only sends rows that remain pending in the confirmed revision. |
| Jobs appear to run twice | Check worker timeout, queue `retry_after` or backend visibility timeout, and worker clock/load. Duplicate delivery is safe for committed rows, but the timeout should prevent routine overlapping execution. |

The worker commands above are examples for Laravel 12 and 13. See the official [Laravel 12 queue guide](https://laravel.com/docs/12.x/queues) and [Laravel 13 queue guide](https://laravel.com/docs/13.x/queues) for version-specific worker options and lifecycle behavior.
