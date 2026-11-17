<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use SmartImport\Services\ImportExecutionService;
use SmartImport\Tests\Fixtures\PreviewImportDefinition;
use SmartImport\SmartImportServiceProvider;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $sessionId, $rowId, $revision, $readyFile, $releaseFile, $resultFile] = $argv;

try {
    TestbenchApplication::create(options: [
        'extra' => [
            'providers' => [SmartImportServiceProvider::class],
        ],
    ])->make(Kernel::class)->bootstrap();
    $sharedSqlitePath = getenv('SMART_IMPORT_RACE_SQLITE_PATH');

    if (is_string($sharedSqlitePath) && $sharedSqlitePath !== '') {
        $connectionName = config('database.default');
        config()->set("database.connections.{$connectionName}.database", $sharedSqlitePath);
        DB::purge($connectionName);
    }

    config()->set('smart-import.importers', [PreviewImportDefinition::class]);
    file_put_contents($readyFile, 'ready');

    $deadline = microtime(true) + 15;

    while (! is_file($releaseFile) && microtime(true) < $deadline) {
        usleep(10_000);
    }

    if (! is_file($releaseFile)) {
        throw new RuntimeException('The concurrent execution barrier timed out.');
    }

    $execution = app(ImportExecutionService::class);
    $attempt = 0;

    while (true) {
        try {
            $result = $execution->processRow($sessionId, (int) $rowId, (int) $revision);

            break;
        } catch (DeadlockException $exception) {
            if (++$attempt >= 5) {
                throw $exception;
            }

            usleep(100_000 * $attempt);
        }
    }

    file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode(['error' => $exception::class], JSON_THROW_ON_ERROR));
    fwrite(STDERR, $exception::class.PHP_EOL);
    exit(1);
}
