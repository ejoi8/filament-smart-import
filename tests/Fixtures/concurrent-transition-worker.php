<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use SmartImport\Enums\ImportStatus;
use SmartImport\Services\ImportCoordinator;
use SmartImport\SmartImportServiceProvider;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $sessionId, $readyFile, $resultFile] = $argv;

try {
    TestbenchApplication::create(options: [
        'extra' => [
            'providers' => [SmartImportServiceProvider::class],
        ],
    ])->make(Kernel::class)->bootstrap();

    Gate::define('update', static fn (): bool => true);
    file_put_contents($readyFile, 'ready');

    $changed = app(ImportCoordinator::class)->transition(
        $sessionId,
        ImportStatus::Uploaded,
        new GenericUser(['id' => 7]),
    );

    file_put_contents($resultFile, $changed ? '1' : '0');
} catch (Throwable $exception) {
    file_put_contents($resultFile, 'error: '.$exception->getMessage());
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
