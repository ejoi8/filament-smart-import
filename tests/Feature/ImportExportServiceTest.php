<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use SmartImport\Enums\ImportStatus;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportOutboxEvent;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportExportService;
use SmartImport\Services\ImportStagingService;
use SmartImport\Tests\Fixtures\ExportTemplateImportDefinition;
use SmartImport\Tests\Fixtures\SensitiveExportImportDefinition;
use SmartImport\Tests\Fixtures\StagingImportDefinition;
use SmartImport\Tests\TestCase;

class ImportExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('import_target_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('department')->nullable();
            $table->timestamps();
        });

        Storage::fake('local');
        Queue::fake();
        config()->set('smart-import.importers', [
            StagingImportDefinition::class,
            ExportTemplateImportDefinition::class,
            SensitiveExportImportDefinition::class,
        ]);
        Gate::define('create', fn (): bool => true);
        Gate::define('view', fn (): bool => true);
        Gate::define('update', fn (): bool => true);
        Gate::define('useImporter', fn (): bool => true);
        Gate::define('export', fn (): bool => true);
    }

    #[Test]
    public function csv_template_streams_canonical_field_names(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $lines = iterator_to_array($this->exports()->templateCsv('export-template', $actor));

        $this->assertSame("name,email\r\n", implode('', $lines));
    }

    #[Test]
    public function xlsx_template_uses_literal_cells_and_includes_a_field_guide(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $contents = $this->exports()->templateXlsx('export-template', $actor);
        $path = tempnam(sys_get_temp_dir(), 'smart-import-template-');
        self::assertNotFalse($path);

        try {
            file_put_contents($path, $contents);
            $spreadsheet = IOFactory::load($path);

            $this->assertSame(['Import Data', 'Field Guide'], $spreadsheet->getSheetNames());
            $this->assertSame('name', $spreadsheet->getSheet(0)->getCell('A1')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $spreadsheet->getSheet(0)->getCell('A1')->getDataType());
            $this->assertSame(['Field', 'Label', 'Type', 'Required'], $spreadsheet->getSheet(1)->rangeToArray('A1:D1')[0]);
            $this->assertSame(['name', 'Name', 'string', 'Yes'], $spreadsheet->getSheet(1)->rangeToArray('A2:D2')[0]);
            $spreadsheet->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function validation_error_csv_streams_field_errors_and_neutralizes_formula_values(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email\nAda,\"=HYPERLINK(\"\"https://example.test\"\",\"\"open\"\")\"\n", $actor);
        $staging = $this->app->make(ImportStagingService::class)->start(
            (string) $session->getKey(),
            $actor,
            'staging-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email'],
        );

        (new StageImportJob((string) $session->getKey(), 1, 1))->handle($this->app->make(ImportStagingService::class));

        $csv = implode('', iterator_to_array($this->exports()->validationErrorsCsv((string) $session->getKey(), $actor)));
        $rows = array_map('str_getcsv', array_filter(explode("\r\n", trim($csv))));

        $this->assertSame(['source_row', 'source_sheet', 'field', 'code', 'safe_message', 'value'], $rows[0]);
        $this->assertSame('2', $rows[1][0]);
        $this->assertSame('email', $rows[1][2]);
        $this->assertSame('email', $rows[1][3]);
        $this->assertStringStartsWith("'=", $rows[1][5]);
        $this->assertStringNotContainsString("\r\n=HYPERLINK", $csv);
    }

    #[Test]
    public function validation_error_export_requires_the_export_gate(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email\nAda,invalid\n", $actor);
        $this->app->make(ImportStagingService::class)->start(
            (string) $session->getKey(),
            $actor,
            'staging-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email'],
        );
        (new StageImportJob((string) $session->getKey(), 1, 1))->handle($this->app->make(ImportStagingService::class));
        Gate::define('export', fn (): bool => false);

        $this->expectException(AuthorizationException::class);
        $this->exports()->validationErrorsCsv((string) $session->getKey(), $actor);
    }

    #[Test]
    public function failure_csv_uses_generic_reasons_redacts_sensitive_values_and_escapes_authorized_values(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload(
            "Name,Email,API token\nAda,ada@example.test,=cmd\nBob,bob@example.test,safe@example.test\n",
            $actor,
        );
        $this->app->make(ImportStagingService::class)->start(
            (string) $session->getKey(),
            $actor,
            'sensitive-export',
            0,
            1,
            [1 => 'name', 2 => 'email', 3 => 'api_token'],
        );
        (new StageImportJob((string) $session->getKey(), 1, 1))->handle($this->app->make(ImportStagingService::class));

        $rows = $session->fresh()->rows()->orderBy('source_row_number')->get();
        $rows[0]->forceFill([
            'validation_errors' => [[
                'field' => 'api_token',
                'code' => 'email',
                'safe_message' => 'PRIVATE-CUSTOM-VALIDATION-MESSAGE',
                'source_row' => 2,
            ]],
        ])->save();
        $rows[1]->forceFill([
            'execution_status' => 'failed',
            'last_error_code' => 'execution_failed',
            'last_error_message' => 'PRIVATE-EXECUTION-ERROR',
        ])->save();

        $deniedCsv = implode('', iterator_to_array($this->exports()->validationErrorsCsv((string) $session->getKey(), $actor)));
        $deniedRows = array_map('str_getcsv', array_filter(explode("\r\n", trim($deniedCsv))));

        $this->assertSame('The value is not a valid email address.', $deniedRows[1][4]);
        $this->assertSame('', $deniedRows[1][5]);
        $this->assertSame('execution_failed', $deniedRows[2][3]);
        $this->assertSame('The row could not be processed under the confirmed import rules.', $deniedRows[2][4]);
        $this->assertStringNotContainsString('PRIVATE-CUSTOM-VALIDATION-MESSAGE', $deniedCsv);
        $this->assertStringNotContainsString('PRIVATE-EXECUTION-ERROR', $deniedCsv);
        $this->assertStringNotContainsString('=cmd', $deniedCsv);

        Gate::define('viewSensitiveImportFields', fn ($user, $authorizedSession, $field): bool =>
            $authorizedSession->is($session)
            && $field->name === 'api_token'
        );
        $allowedCsv = implode('', iterator_to_array($this->exports()->validationErrorsCsv((string) $session->getKey(), $actor)));
        $allowedRows = array_map('str_getcsv', array_filter(explode("\r\n", trim($allowedCsv))));

        $this->assertSame("'=cmd", $allowedRows[1][5]);
        $this->assertSame('The value is not a valid email address.', $allowedRows[1][4]);
        $this->assertStringNotContainsString("\r\n=cmd", $allowedCsv);
    }

    #[Test]
    public function summary_csv_contains_safe_import_counts_and_formula_protected_metadata(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $file = UploadedFile::fake()->createWithContent('=1+1.csv', "Name,Email\nAda,ada@example.test\n");
        $session = $this->app->make(FileIntakeService::class)->upload($file, $actor);
        $session->forceFill([
            'status' => ImportStatus::Cancelled,
            'stage_total' => 3,
            'stage_processed' => 3,
            'stage_valid' => 2,
            'stage_invalid' => 1,
            'stage_warning' => 1,
            'execution_total' => 2,
            'processed' => 1,
            'created_count' => 1,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'attempt_history' => [
                ['attempt' => 1, 'status' => 'completed'],
                ['attempt' => 2, 'status' => 'cancelled'],
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ])->save();
        foreach ([
            ['execution_status' => 'skipped', 'last_error_code' => 'unchanged'],
            ['execution_status' => 'excluded', 'last_error_code' => null],
            ['execution_status' => 'failed', 'last_error_code' => 'target_changed_after_preview'],
        ] as $index => $outcome) {
            ImportRow::forceCreate($outcome + [
                'import_id' => $session->getKey(),
                'source_row_number' => $index + 2,
            ]);
        }
        ImportOutboxEvent::forceCreate([
            'import_id' => $session->getKey(),
            'attempt_id' => 2,
            'terminal_status' => 'cancelled',
            'event_key' => hash('sha256', 'summary-delivered-event'),
            'payload_version' => 1,
            'payload' => ['status' => 'cancelled'],
            'delivery_status' => 'delivered',
            'attempts' => 1,
            'available_at' => now(),
            'delivered_at' => now(),
        ]);

        $csv = implode('', iterator_to_array($this->exports()->summaryCsv((string) $session->getKey(), $actor)));
        $rows = array_map('str_getcsv', array_filter(explode("\r\n", trim($csv))));
        $summary = array_column(array_slice($rows, 1), 1, 0);

        $this->assertSame("'=1+1.csv", $summary['filename']);
        $this->assertSame('cancelled', $summary['status']);
        $this->assertSame('3', $summary['rows_in_source']);
        $this->assertSame('1', $summary['created']);
        $this->assertSame('1', $summary['not_processed']);
        $this->assertSame('1', $summary['cancelled']);
        $this->assertSame('1', $summary['unchanged']);
        $this->assertSame('1', $summary['excluded']);
        $this->assertSame('1', $summary['snapshot_conflicts']);
        $this->assertSame('2', $summary['attempts']);
        $this->assertSame('delivered', $summary['notification_delivery_status']);
        $this->assertStringNotContainsString("\r\n=1+1.csv", $csv);
    }

    #[Test]
    public function summary_report_requires_the_export_gate(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email\nAda,ada@example.test\n", $actor);
        Gate::define('export', fn (): bool => false);

        $this->expectException(AuthorizationException::class);
        $this->exports()->summaryCsv((string) $session->getKey(), $actor);
    }

    private function exports(): ImportExportService
    {
        return $this->app->make(ImportExportService::class);
    }

    private function upload(string $contents, GenericUser $actor, string $filename = 'contacts.csv'): \SmartImport\Models\ImportSession
    {
        $file = UploadedFile::fake()->createWithContent($filename, $contents);

        return $this->app->make(FileIntakeService::class)->upload($file, $actor);
    }
}
