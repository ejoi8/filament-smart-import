<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\ImportStatus;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Tests\TestCase;

class ImportCoordinatorConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[Test]
    public function concurrent_identical_transitions_have_only_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This concurrency test requires MySQL or MariaDB row locks.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('This concurrency test requires proc_open.');
        }

        Gate::define('create', static fn (): bool => true);
        Gate::define('update', static fn (): bool => true);

        $actor = new GenericUser(['id' => 7]);
        $session = app(ImportCoordinator::class)->create($this->sourceAttributes(), $actor);
        $connection = DB::connection();
        $connectionName = $connection->getName();
        $workerDirectory = sys_get_temp_dir().'/smart-import-concurrency-'.bin2hex(random_bytes(8));
        mkdir($workerDirectory, 0700, true);

        $readyFiles = [
            $workerDirectory.'/ready-0',
            $workerDirectory.'/ready-1',
        ];
        $resultFiles = [
            $workerDirectory.'/result-0',
            $workerDirectory.'/result-1',
        ];
        $outputFiles = [
            $workerDirectory.'/output-0',
            $workerDirectory.'/output-1',
        ];
        $errorFiles = [
            $workerDirectory.'/error-0',
            $workerDirectory.'/error-1',
        ];
        $workers = [];
        $transactionOpen = false;

        try {
            $connection->beginTransaction();
            $transactionOpen = true;

            $lockedSession = $connection
                ->table($session->getTable())
                ->where('id', $session->id)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull($lockedSession);

            foreach ($readyFiles as $index => $readyFile) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__.'/../Fixtures/concurrent-transition-worker.php',
                    $session->id,
                    $readyFile,
                    $resultFiles[$index],
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['file', $outputFiles[$index], 'a'],
                    2 => ['file', $errorFiles[$index], 'a'],
                ], $pipes);

                if (! is_resource($process)) {
                    throw new \RuntimeException('Unable to start a concurrent transition worker.');
                }

                fclose($pipes[0]);
                $workers[] = [
                    'process' => $process,
                    'error_file' => $errorFiles[$index],
                ];
            }

            $deadline = microtime(true) + 10;

            while (microtime(true) < $deadline) {
                if (count(array_filter($readyFiles, 'is_file')) === count($readyFiles)) {
                    break;
                }

                usleep(10_000);
            }

            $allWorkersReady = count(array_filter($readyFiles, 'is_file')) === count($readyFiles);

            if ($allWorkersReady) {
                // Give both workers time to reach the held row lock before releasing it.
                usleep(100_000);
                $connection->commit();
            } else {
                $connection->rollBack();
            }

            $transactionOpen = false;

            foreach ($workers as $worker) {
                $process = $worker['process'];
                $workerDeadline = microtime(true) + 15;
                $status = proc_get_status($process);

                while ($status['running'] && microtime(true) < $workerDeadline) {
                    usleep(10_000);
                    $status = proc_get_status($process);
                }

                if ($status['running']) {
                    proc_terminate($process);
                    proc_close($process);
                    $this->fail('A concurrent transition worker timed out.');
                }

                proc_close($process);
                $this->assertSame(
                    0,
                    $status['exitcode'],
                    'A transition worker failed: '.(string) file_get_contents($worker['error_file']),
                );
            }

            $this->assertTrue($allWorkersReady, 'Both transition workers must start before the lock is released.');

            $results = array_map(static fn (string $path): string => (string) file_get_contents($path), $resultFiles);
            sort($results);

            $this->assertSame(['0', '1'], $results);
            $this->assertSame(ImportStatus::Uploaded, $session->fresh()->status);
        } finally {
            if ($transactionOpen) {
                $connection->rollBack();
            }

            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    $status = proc_get_status($worker['process']);

                    if ($status['running']) {
                        proc_terminate($worker['process']);
                    }

                    proc_close($worker['process']);
                }
            }

            foreach ([...$readyFiles, ...$resultFiles, ...$outputFiles, ...$errorFiles] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir($workerDirectory)) {
                rmdir($workerDirectory);
            }
        }
    }

    /** @return array<string, mixed> */
    private function sourceAttributes(): array
    {
        $path = 'smart-import/concurrency/'.bin2hex(random_bytes(8)).'.csv';

        return [
            'source' => 'cli',
            'original_filename' => 'concurrent.csv',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'file_hash' => hash('sha256', $path),
            'file_size' => 10,
            'file_type' => 'csv',
        ];
    }
}
