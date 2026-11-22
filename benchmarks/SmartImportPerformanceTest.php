<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportStagingService;
use SmartImport\Services\SpreadsheetReader;
use SmartImport\Tests\Fixtures\StagingImportDefinition;
use SmartImport\Tests\TestCase;

final class SmartImportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private bool $captureQueries = false;

    private int $queryCount = 0;

    /** @var array<string, int> */
    private array $queryShapes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        Schema::create('import_target_models', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('department')->nullable();
            $table->timestamps();
        });
        config()->set('smart-import.importers', [StagingImportDefinition::class]);
        config()->set('smart-import.uploads.max_files_per_actor_per_hour', 100);
        config()->set('smart-import.reading.chunk_size', 250);
        foreach (['create', 'view', 'update', 'useImporter'] as $ability) {
            Gate::define($ability, static fn (): bool => true);
        }

        DB::listen(function (QueryExecuted $query): void {
            if (! $this->captureQueries) {
                return;
            }

            $this->queryCount++;
            $shape = preg_replace('/\s+/', ' ', trim($query->sql)) ?? $query->sql;
            $this->queryShapes[$shape] = ($this->queryShapes[$shape] ?? 0) + 1;
        });
    }

    #[Test]
    public function prints_baseline_reader_and_staging_measurements(): void
    {
        $csv = $this->csv(10000, 2, false);
        $session = $this->upload('narrow-10k.csv', $csv, 1);
        $bytes = strlen($csv);
        unset($csv);
        $this->measureReader('csv_10k_narrow', $session, $bytes);

        $csv = $this->csv(5000, 64, false);
        $session = $this->upload('wide-5k.csv', $csv, 2);
        $bytes = strlen($csv);
        unset($csv);
        $this->measureReader('csv_5k_64_columns', $session, $bytes);

        $csv = $this->csv(10000, 2, true);
        $session = $this->upload('duplicates-10k.csv', $csv, 3);
        $bytes = strlen($csv);
        unset($csv);
        $this->measureStaging('csv_10k_duplicate_heavy_staging', $session, $bytes);

        foreach (['xlsx', 'xls'] as $index => $extension) {
            $path = sys_get_temp_dir().'/smart-import-plan-benchmark-'.uniqid('', true).'.'.$extension;
            $workbook = new Spreadsheet();
            $sheet = $workbook->getActiveSheet();
            for ($column = 1; $column <= 12; $column++) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($column).'1', $column === 1 ? 'Name' : 'Field '.$column);
            }
            for ($row = 2; $row <= 1001; $row++) {
                for ($column = 1; $column <= 12; $column++) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($column).$row, $column === 1 ? 'Contact '.$row : 'value-'.$row.'-'.$column);
                }
            }
            ($extension === 'xls' ? new Xls($workbook) : new Xlsx($workbook))->save($path);
            $workbook->disconnectWorksheets();
            unset($workbook, $sheet);
            $session = app(FileIntakeService::class)->upload(
                new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'workbook.'.$extension, null, UPLOAD_ERR_OK, true),
                new GenericUser(['id' => 4 + $index]),
            );
            $this->measureReader(strtoupper($extension).'_1000_rows_12_columns', $session, (int) $session->file_size);
        }
    }

    #[Test]
    public function prints_maximum_row_boundary_reader_measurement(): void
    {
        $csv = $this->csv(99999, 2, false);
        $bytes = strlen($csv);
        if ($bytes > (int) config('smart-import.uploads.max_file_size_bytes')) {
            self::fail('The generated row-boundary fixture exceeds the configured file-size maximum.');
        }
        $session = $this->upload('rows-maximum.csv', $csv, 10);
        unset($csv);
        $this->measureReader('csv_99999_data_rows_100000_source_rows', $session, $bytes);
    }

    #[Test]
    public function prints_maximum_file_size_boundary_measurement(): void
    {
        $columns = 160;
        $headers = array_map(static fn (int $column): string => 'Field '.$column, range(1, $columns));
        $cell = str_repeat('x', 32000);
        $row = implode(',', array_fill(0, $columns, $cell));
        $contents = implode(',', $headers)."\n".$row."\n".$row."\n";
        $bytes = strlen($contents);
        self::assertLessThanOrEqual((int) config('smart-import.uploads.max_file_size_bytes'), $bytes);
        self::assertLessThanOrEqual((int) config('smart-import.reading.max_cell_length'), strlen($cell));
        $session = $this->upload('bytes-maximum.csv', $contents, 11);
        unset($contents, $row, $cell);

        $this->measureReader('csv_10mb_160_columns_2_long_rows', $session, $bytes);
    }

    private function measureReader(string $name, \SmartImport\Models\ImportSession $session, int $bytes): void
    {
        $reader = app(SpreadsheetReader::class);
        $rows = 0;
        $columns = 0;
        $this->measure($name, $bytes, function () use ($reader, $session, &$rows, &$columns): void {
            foreach ($reader->readRows($session, 0) as $row) {
                $rows++;
                $columns = max($columns, count($row['values']));
            }
        }, function () use (&$rows, &$columns): array {
            return ['rows' => $rows, 'max_columns' => $columns];
        });
        $this->assertGreaterThan(0, $rows);
        $this->assertGreaterThan(0, $columns);
    }

    private function measureStaging(string $name, \SmartImport\Models\ImportSession $session, int $bytes): void
    {
        $actor = new GenericUser(['id' => 3]);
        $staging = app(ImportStagingService::class);
        $this->measure($name, $bytes, function () use ($staging, $session, $actor): void {
            $queued = $staging->start((string) $session->getKey(), $actor, 'staging-contacts', 0, 1, [1 => 'name', 2 => 'email']);
            (new StageImportJob((string) $queued->getKey(), $queued->mapping_revision, $queued->staging_revision))->handle($staging);
        }, function () use ($session): array {
            $completed = $session->fresh();
            return [
                'status' => $completed->status?->value,
                'rows' => $completed->stage_processed,
                'valid' => $completed->stage_valid,
                'invalid' => $completed->stage_invalid,
                'sqlite_query_plans' => $this->sqliteQueryPlans((string) $session->getKey()),
            ];
        });
        $this->assertSame('review_ready', $session->fresh()->status?->value);
        $this->assertSame(10000, $session->fresh()->stage_processed);
    }

    private function measure(string $name, int $bytes, callable $operation, callable $details): void
    {
        $this->queryCount = 0;
        $this->queryShapes = [];
        $this->captureQueries = true;
        memory_reset_peak_usage();
        $startMemory = memory_get_usage(false);
        $start = hrtime(true);
        try {
            $operation();
        } finally {
            $this->captureQueries = false;
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000_000;
        $peakDelta = max(0, memory_get_peak_usage(false) - $startMemory);
        arsort($this->queryShapes);
        fwrite(STDOUT, "\nSMART_IMPORT_BENCHMARK ".json_encode([
            'scenario' => $name,
            'input_bytes' => $bytes,
            'seconds' => round($elapsed, 3),
            'peak_memory_delta_bytes' => $peakDelta,
            'database_queries' => $this->queryCount,
            'common_query_shapes' => array_slice($this->queryShapes, 0, 5, true),
            'chunk_size' => config('smart-import.reading.chunk_size'),
            'execution_chunk_size' => config('smart-import.execution.chunk_size'),
            'queue_connection' => config('smart-import.queue.connection') ?? config('queue.default'),
            'worker' => 'in-process direct service invocation (not a real queue worker)',
        ] + $details(), JSON_THROW_ON_ERROR)."\n");
    }

    private function upload(string $filename, string $contents, int $actorId): \SmartImport\Models\ImportSession
    {
        return app(FileIntakeService::class)->upload(
            UploadedFile::fake()->createWithContent($filename, $contents),
            new GenericUser(['id' => $actorId]),
        );
    }

    private function csv(int $rows, int $columns, bool $duplicate): string
    {
        $headers = ['Name', 'Email'];
        for ($column = 3; $column <= $columns; $column++) {
            $headers[] = 'Field '.$column;
        }
        $contents = implode(',', $headers)."\n";
        for ($row = 1; $row <= $rows; $row++) {
            $values = ['Contact '.$row, $duplicate ? 'duplicate@example.test' : 'contact'.$row.'@example.test'];
            for ($column = 3; $column <= $columns; $column++) {
                $values[] = 'v'.$row.'-'.$column;
            }
            $contents .= implode(',', $values)."\n";
        }
        return $contents;
    }

    /** @return array<string, list<string>> */
    private function sqliteQueryPlans(string $sessionId): array
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return [];
        }

        $queries = [
            'duplicate_lookup' => [
                'EXPLAIN QUERY PLAN SELECT id FROM smart_import_rows WHERE import_id = ? AND match_key_hash IN (?) AND id > ? ORDER BY id ASC LIMIT 250',
                [$sessionId, str_repeat('a', 64), 0],
            ],
            'retry_batch' => [
                'EXPLAIN QUERY PLAN SELECT id FROM smart_import_rows WHERE import_id = ? AND execution_status = ? AND id > ? ORDER BY id ASC LIMIT 250',
                [$sessionId, 'failed', 0],
            ],
            'audit_history' => [
                'EXPLAIN QUERY PLAN SELECT id FROM smart_import_audit_entries WHERE import_id = ? ORDER BY id DESC LIMIT 50',
                [$sessionId],
            ],
        ];

        $plans = [];

        foreach ($queries as $name => [$sql, $bindings]) {
            $plans[$name] = array_map(
                static fn (object $step): string => (string) $step->detail,
                DB::select($sql, $bindings),
            );
        }

        return $plans;
    }
}
