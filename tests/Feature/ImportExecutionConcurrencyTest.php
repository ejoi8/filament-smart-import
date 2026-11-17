<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImporterRegistry;
use SmartImport\Services\RowDataProcessor;
use SmartImport\Services\StagedRowService;
use SmartImport\Services\TargetRecordMatcher;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\Fixtures\PreviewImportDefinition;
use SmartImport\Tests\TestCase;

class ImportExecutionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private static ?string $sharedSqlitePath = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $connectionName = $app['config']->get('database.default');

        if ($app['config']->get("database.connections.{$connectionName}.driver") === 'sqlite') {
            self::$sharedSqlitePath = sys_get_temp_dir().'/smart-import-execution-'.bin2hex(random_bytes(8)).'.sqlite';
            $app['config']->set("database.connections.{$connectionName}.database", self::$sharedSqlitePath);
            putenv('SMART_IMPORT_RACE_SQLITE_PATH='.self::$sharedSqlitePath);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (self::$sharedSqlitePath !== null && is_file(self::$sharedSqlitePath)) {
            unlink(self::$sharedSqlitePath);
        }

        self::$sharedSqlitePath = null;
        putenv('SMART_IMPORT_RACE_SQLITE_PATH');
    }

    #[Test]
    public function simultaneous_same_key_imports_have_one_create_and_one_safe_stale_failure(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'sqlite'], true)) {
            $this->markTestSkipped('This concurrent execution test requires MySQL, MariaDB, or SQLite unique-index support.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('This concurrent execution test requires proc_open.');
        }

        config()->set('smart-import.importers', [PreviewImportDefinition::class]);
        Gate::define('create', static fn (): bool => true);
        Schema::create('import_target_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('api_token')->nullable();
            $table->string('department')->nullable();
            $table->timestamps();
        });

        $actor = new GenericUser(['id' => 7]);
        $sessions = [
            $this->confirmedSession($actor, 'First competing row', 'same-key@example.test'),
            $this->confirmedSession($actor, 'Second competing row', 'same-key@example.test'),
        ];
        $rows = array_map(static fn (ImportSession $session): ImportRow => $session->rows()->firstOrFail(), $sessions);
        $workerDirectory = sys_get_temp_dir().'/smart-import-execution-race-'.bin2hex(random_bytes(8));
        mkdir($workerDirectory, 0700, true);
        $releaseFile = $workerDirectory.'/release';
        $readyFiles = [$workerDirectory.'/ready-0', $workerDirectory.'/ready-1'];
        $resultFiles = [$workerDirectory.'/result-0', $workerDirectory.'/result-1'];
        $outputFiles = [$workerDirectory.'/output-0', $workerDirectory.'/output-1'];
        $errorFiles = [$workerDirectory.'/error-0', $workerDirectory.'/error-1'];
        $workers = [];

        try {
            foreach ($sessions as $index => $session) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__.'/../Fixtures/concurrent-import-worker.php',
                    (string) $session->getKey(),
                    (string) $rows[$index]->getKey(),
                    (string) $session->confirmed_revision,
                    $readyFiles[$index],
                    $releaseFile,
                    $resultFiles[$index],
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['file', $outputFiles[$index], 'a'],
                    2 => ['file', $errorFiles[$index], 'a'],
                ], $pipes);

                if (! is_resource($process)) {
                    throw new \RuntimeException('Unable to start a concurrent row execution worker.');
                }

                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'error_file' => $errorFiles[$index]];
            }

            $readyDeadline = microtime(true) + 15;

            while (microtime(true) < $readyDeadline && count(array_filter($readyFiles, 'is_file')) !== 2) {
                usleep(10_000);
            }

            $allWorkersReady = count(array_filter($readyFiles, 'is_file')) === 2;

            if ($allWorkersReady) {
                file_put_contents($releaseFile, 'go');
            }

            foreach ($workers as $worker) {
                $process = $worker['process'];
                $deadline = microtime(true) + 30;
                $status = proc_get_status($process);

                while ($status['running'] && microtime(true) < $deadline) {
                    usleep(10_000);
                    $status = proc_get_status($process);
                }

                if ($status['running']) {
                    proc_terminate($process);
                    proc_close($process);
                    $this->fail('A concurrent row execution worker timed out.');
                }

                proc_close($process);
                $this->assertSame(
                    0,
                    $status['exitcode'],
                    'A row execution worker failed: '.(string) file_get_contents($worker['error_file']),
                );
            }

            $this->assertTrue($allWorkersReady, 'Both row workers must reach the barrier before execution starts.');
            $results = array_map(static fn (string $path): array => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), $resultFiles);
            $statuses = array_column($results, 'execution_status');
            sort($statuses);

            $this->assertSame(['created', 'failed'], $statuses);
            $this->assertSame(1, ImportTargetModel::query()->where('email', 'same-key@example.test')->count());
            $this->assertSame(2, ImportSession::query()->whereIn('id', array_map(static fn (ImportSession $session): string => (string) $session->getKey(), $sessions))->sum('processed'));
            $this->assertSame(1, ImportSession::query()->whereIn('id', array_map(static fn (ImportSession $session): string => (string) $session->getKey(), $sessions))->sum('created_count'));
            $this->assertSame(1, ImportSession::query()->whereIn('id', array_map(static fn (ImportSession $session): string => (string) $session->getKey(), $sessions))->sum('failed_count'));
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    $status = proc_get_status($worker['process']);

                    if ($status['running']) {
                        proc_terminate($worker['process']);
                    }

                    proc_close($worker['process']);
                }
            }

            foreach ([...$readyFiles, ...$resultFiles, ...$outputFiles, ...$errorFiles, $releaseFile] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir($workerDirectory)) {
                rmdir($workerDirectory);
            }
        }
    }

    private function confirmedSession(GenericUser $actor, string $name, string $email): ImportSession
    {
        $definition = app(ImporterRegistry::class)->get('preview-contacts');
        $mapping = [1 => 'name', 2 => 'email', 3 => 'api_token', 4 => 'department'];
        $source = [1 => $name, 2 => $email, 3 => '', 4 => 'Sales'];
        $processed = app(RowDataProcessor::class)->process($definition, $source, $mapping, 2);
        $match = app(TargetRecordMatcher::class)->matchBatch($definition, [$processed['normalized_values']])[0];
        $session = app(ImportCoordinator::class)->create([
            'source' => 'cli',
            'original_filename' => 'race.csv',
            'storage_disk' => 'local',
            'storage_path' => 'race/'.bin2hex(random_bytes(8)).'.csv',
            'file_hash' => hash('sha256', $name.$email),
            'file_size' => 100,
            'file_type' => 'csv',
        ], $actor);
        $session->forceFill([
            'importer_key' => $definition->key(),
            'importer_version' => $definition->version(),
            'target_model' => $definition->targetModel(),
            'intake_status' => IntakeStatus::Ready,
            'status' => ImportStatus::Queued,
            'mapping' => $mapping,
            'mapping_revision' => 1,
            'staging_revision' => 1,
            'confirmed_revision' => 1,
            'execution_total' => 1,
        ]);
        $session->save();
        $decision = $definition->writePolicy()->decisionFor($match['change_type']);
        $row = new ImportRow();
        $row->forceFill([
            'import_id' => $session->getKey(),
            'source_row_number' => 2,
            'raw_values' => $source,
            'mapped_values' => $processed['mapped_values'],
            'normalized_values' => $processed['normalized_values'],
            'validation_errors' => [],
            'validation_status' => 'valid',
            'execution_status' => 'pending',
            'match_key_hash' => app(StagedRowService::class)->matchKeyHash($definition, $processed['normalized_values']),
            'resolved_target_type' => null,
            'resolved_target_id' => null,
            'change_type' => $match['change_type'],
            'staging_revision' => 1,
            'preview' => [
                'action' => $decision['action']->value,
                'reason' => $decision['reason'],
                'target_type' => $match['target_type'],
                'target_id' => $match['target_id'],
                'changes' => [],
                'mapping_revision' => 1,
                'staging_revision' => 1,
            ],
        ]);
        $row->save();

        return $session;
    }
}
