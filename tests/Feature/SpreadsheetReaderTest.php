<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SmartImport\Models\ImportSession;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\SpreadsheetReader;
use SmartImport\Tests\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SpreadsheetReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Gate::define('create', fn (): bool => true);
    }

    #[Test]
    public function csv_rows_are_read_in_chunks_with_literal_values_and_original_row_numbers(): void
    {
        config()->set('smart-import.reading.chunk_size', 2);
        $session = $this->uploadCsv("name,code\nAda,0012\n\nGrace,0007\n");
        $reader = $this->reader();

        $this->assertSame([
            [
                'index' => 0,
                'name' => 'Worksheet',
                'rows' => 4,
                'columns' => 2,
                'state' => 'visible',
            ],
        ], $reader->listSheets($session));

        $rows = iterator_to_array($reader->readRows($session, 0), false);

        $this->assertSame([1, 2, 4], array_column($rows, 'row_number'));
        $this->assertSame('0012', $rows[1]['values'][2]);
        $this->assertSame('0007', $rows[2]['values'][2]);
    }

    #[Test]
    public function preview_supports_manual_delimiters_and_header_rows_below_introductory_lines(): void
    {
        $session = $this->uploadCsv("Monthly report\nCreated by Smart Import\nName;Code\nAda;0012\nGrace;0007\n");

        $preview = $this->reader()->preview(
            $session,
            0,
            sampleRows: 5,
            headerRow: 3,
            csvDelimiter: ';',
        );

        $this->assertSame(0, $preview['sheet_index']);
        $this->assertSame('Worksheet', $preview['sheet_name']);
        $this->assertSame(1, $preview['sample_start_row']);
        $this->assertSame(5, $preview['sample_end_row']);
        $this->assertSame(3, $preview['header_row']);
        $this->assertSame([1 => 'Name', 2 => 'Code'], $preview['headers']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($preview['rows'], 'row_number'));
        $this->assertSame('0012', $preview['rows'][3]['values'][2]);
    }

    #[Test]
    public function preview_preserves_source_positions_and_allows_files_without_headers(): void
    {
        $session = $this->uploadCsv("first,second\nAda,0012\n\nGrace,0007\n");

        $preview = $this->reader()->preview(
            $session,
            0,
            sampleRows: 4,
        );

        $this->assertNull($preview['header_row']);
        $this->assertNull($preview['headers']);
        $this->assertSame([1, 2, 4], array_column($preview['rows'], 'row_number'));
        $this->assertSame('first', $preview['rows'][0]['values'][1]);
    }

    #[Test]
    public function preview_uses_a_selected_encoding_or_csv_encoding_autodetection(): void
    {
        $session = $this->uploadCsv("city\nMontr\xE9al\n");
        $reader = $this->reader();

        $selected = $reader->preview($session, 0, sampleRows: 2, headerRow: 1, csvEncoding: 'CP1252');
        $guessed = $reader->preview($session, 0, sampleRows: 2, headerRow: 1);

        $this->assertSame('Montréal', $selected['rows'][1]['values'][1]);
        $this->assertSame('Montréal', $guessed['rows'][1]['values'][1]);
    }

    #[Test]
    public function preview_rejects_header_rows_outside_the_window_or_on_an_empty_source_row(): void
    {
        $session = $this->uploadCsv("Title\n\nName\nAda\n");
        $reader = $this->reader();

        try {
            $reader->preview($session, 0, sampleRows: 3, headerRow: 4);
            $this->fail('A header outside the selected preview window must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('inside the preview sample window', $exception->getMessage());
        }

        try {
            $reader->preview($session, 0, sampleRows: 3, headerRow: 2);
            $this->fail('An empty source row cannot be selected as a header.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('header row is empty', $exception->getMessage());
        }
    }

    #[Test]
    public function preview_enforces_configured_sample_limits_and_rejects_unsafe_csv_options(): void
    {
        $session = $this->uploadCsv("name;code\nAda;0012\nGrace;0007\n");
        config()->set('smart-import.reading.preview_rows', 2);
        config()->set('smart-import.reading.max_preview_rows', 2);

        try {
            $this->reader()->preview($session, 0, sampleRows: 3);
            $this->fail('The configured preview sample maximum must be enforced.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('between 1 and 2 source rows', $exception->getMessage());
        }

        try {
            $this->reader()->preview($session, 0, sampleRows: 2, csvDelimiter: "\n");
            $this->fail('Line breaks cannot be selected as CSV delimiters.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('CSV delimiter', $exception->getMessage());
        }

        try {
            $this->reader()->preview($session, 0, sampleRows: 2, csvEncoding: 'invalid encoding');
            $this->fail('Unsupported CSV encodings must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('CSV input encoding is invalid', $exception->getMessage());
        }
    }

    #[Test]
    public function xlsx_and_xls_support_sheet_listing_selection_and_bounded_row_chunks(): void
    {
        config()->set('smart-import.reading.chunk_size', 2);

        foreach (['xlsx', 'xls'] as $extension) {
            $session = $this->uploadWorkbook($extension);
            $reader = $this->reader();

            $this->assertSame(['Contacts', 'Products'], array_column($reader->listSheets($session), 'name'));
            $this->assertSame([0, 1], array_column($reader->listSheets($session), 'index'));

            $contacts = iterator_to_array($reader->readRows($session, 0), false);
            $this->assertSame([1, 2, 4], array_column($contacts, 'row_number'));
            $this->assertSame('0012', $contacts[1]['values'][2]);
            $this->assertSame('=1+1', $contacts[1]['values'][3]);

            $preview = $reader->preview($session, 0, sampleRows: 2, headerRow: 1);
            $this->assertSame('Name', $preview['headers'][1]);
            $this->assertSame([1, 2], array_column($preview['rows'], 'row_number'));

            $products = iterator_to_array($reader->readRows($session, 1), false);
            $this->assertSame([1, 2], array_column($products, 'row_number'));
            $this->assertSame('Pen', $products[1]['values'][1]);
        }
    }

    #[Test]
    public function invalid_sheet_indexes_are_rejected(): void
    {
        $session = $this->uploadWorkbook('xlsx');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('selected worksheet does not exist');

        iterator_to_array($this->reader()->readRows($session, 2), false);
    }

    #[Test]
    public function row_column_and_cell_limits_are_enforced_before_or_during_chunk_reads(): void
    {
        $session = $this->uploadWorkbook('xlsx');
        $reader = $this->reader();

        config()->set('smart-import.reading.max_rows', 3);
        try {
            iterator_to_array($reader->readRows($session, 0), false);
            $this->fail('The configured row limit must reject an oversized worksheet.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('row limit', $exception->getMessage());
        }

        config()->set('smart-import.reading.max_rows', 100000);
        config()->set('smart-import.reading.max_columns', 2);
        try {
            iterator_to_array($reader->readRows($session, 0), false);
            $this->fail('The configured column limit must reject an oversized worksheet.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('column limit', $exception->getMessage());
        }

        config()->set('smart-import.reading.max_columns', 256);
        config()->set('smart-import.reading.max_cell_length', 3);
        try {
            iterator_to_array($reader->readRows($session, 0), false);
            $this->fail('The configured cell-length limit must reject oversized text.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('text length limit', $exception->getMessage());
        }
    }

    #[Test]
    public function xlsx_uncompressed_size_limit_rejects_expanding_archives(): void
    {
        $session = $this->uploadWorkbook('xlsx');
        config()->set('smart-import.reading.max_uncompressed_bytes', 100);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expanded-size limit');

        $this->reader()->listSheets($session);
    }

    #[Test]
    public function stored_source_checksum_is_verified_before_listing_or_reading(): void
    {
        $session = $this->uploadCsv("name\nAda\n");
        Storage::disk('local')->put($session->storage_path, "name\nEve\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('checksum no longer matches');

        $this->reader()->listSheets($session);
    }

    private function reader(): SpreadsheetReader
    {
        return $this->app->make(SpreadsheetReader::class);
    }

    private function uploadCsv(string $contents): ImportSession
    {
        return $this->app->make(FileIntakeService::class)->upload(
            LaravelUploadedFile::fake()->createWithContent('contacts.csv', $contents),
            new GenericUser(['id' => 7]),
        );
    }

    private function uploadWorkbook(string $extension): ImportSession
    {
        $path = sys_get_temp_dir().'/smart-import-reader-'.uniqid('', true).'.'.$extension;
        $spreadsheet = new Spreadsheet();
        $contacts = $spreadsheet->getActiveSheet();
        $contacts->setTitle('Contacts');
        $contacts->setCellValue('A1', 'Name');
        $contacts->setCellValue('B1', 'Code');
        $contacts->setCellValue('C1', 'Formula');
        $contacts->setCellValue('A2', 'Ada');
        $contacts->getCell('B2')->setValueExplicit('0012', DataType::TYPE_STRING);
        $contacts->setCellValue('C2', '=1+1');
        $contacts->setCellValue('A4', 'Grace');
        $contacts->getCell('B4')->setValueExplicit('0007', DataType::TYPE_STRING);

        $products = $spreadsheet->createSheet();
        $products->setTitle('Products');
        $products->setCellValue('A1', 'Product');
        $products->setCellValue('A2', 'Pen');

        $writer = $extension === 'xls' ? new Xls($spreadsheet) : new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            return $this->app->make(FileIntakeService::class)->upload(
                new UploadedFile($path, 'contacts.'.$extension, null, UPLOAD_ERR_OK, true),
                new GenericUser(['id' => 7]),
            );
        } finally {
            @unlink($path);
        }
    }
}
