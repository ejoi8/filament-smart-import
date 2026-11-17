<?php

namespace SmartImport\Services;

use Generator;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;
use SmartImport\Models\ImportSession;
use Throwable;
use ZipArchive;

final class SpreadsheetReader
{
    private const READERS = [
        'csv' => IOFactory::READER_CSV,
        'xlsx' => IOFactory::READER_XLSX,
        'xls' => IOFactory::READER_XLS,
    ];

    private const MAX_CHUNK_SIZE = 2000;

    private const MAX_PREVIEW_ROWS = 100;

    /**
     * @return array<int, array{index: int, name: string, rows: int, columns: int, state: string}>
     */
    public function listSheets(
        ImportSession $session,
        ?string $csvDelimiter = null,
        string $csvEncoding = Csv::GUESS_ENCODING,
    ): array
    {
        $limits = $this->limits();
        $path = $this->materializeSource($session, $limits);

        try {
            $reader = $this->createReader($session, $csvDelimiter, $csvEncoding);
            $worksheets = $this->worksheetInfo($reader, $path, $limits);

            return array_map(
                fn (array $worksheet, int $index): array => [
                    'index' => $index,
                    'name' => $worksheet['worksheetName'],
                    'rows' => (int) $worksheet['totalRows'],
                    'columns' => (int) $worksheet['totalColumns'],
                    'state' => (string) ($worksheet['sheetState'] ?? 'visible'),
                ],
                $worksheets,
                array_keys($worksheets),
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * Read non-empty source rows from one worksheet in bounded chunks.
     * Formula cells are returned as their stored formula strings, not calculated values.
     * Column keys are one-based indexes, and row numbers are the source worksheet rows.
     *
     * @return Generator<int, array{row_number: int, values: array<int, mixed>}>
     */
    public function readRows(
        ImportSession $session,
        int $sheetIndex,
        int $startRow = 1,
        ?string $csvDelimiter = null,
        string $csvEncoding = Csv::GUESS_ENCODING,
    ): Generator
    {
        if ($sheetIndex < 0 || $startRow < 1) {
            throw new InvalidArgumentException('The worksheet index cannot be negative and the starting row must be positive.');
        }

        $limits = $this->limits();
        $path = $this->materializeSource($session, $limits);

        try {
            $reader = $this->createReader($session, $csvDelimiter, $csvEncoding);
            $worksheets = $this->worksheetInfo($reader, $path, $limits);

            if (! array_key_exists($sheetIndex, $worksheets)) {
                throw new InvalidArgumentException('The selected worksheet does not exist in this file.');
            }

            $worksheetInfo = $worksheets[$sheetIndex];
            $this->assertWorksheetDimensions($worksheetInfo, $limits);

            $totalRows = (int) $worksheetInfo['totalRows'];
            $totalColumns = (int) $worksheetInfo['totalColumns'];

            if ($startRow > $totalRows || $totalColumns === 0) {
                return;
            }

            $fileType = strtolower((string) $session->file_type);
            $sheetName = (string) $worksheetInfo['worksheetName'];

            if ($fileType !== 'csv') {
                $reader->setLoadSheetsOnly([$sheetName]);
                $reader->setReadEmptyCells(false);
            } else {
                $reader->setValueBinder((new StringValueBinder())->setNullConversion(false));
            }

            $reader->setReadDataOnly(true);
            $chunkSize = $limits['chunk_size'];
            $maximumColumns = $limits['max_columns'];

            $filter = new class($maximumColumns) implements IReadFilter
            {
                private int $startRow = 1;

                private int $endRow = 1;

                public function __construct(private int $maximumColumns) {}

                public function setRows(int $startRow, int $endRow): void
                {
                    $this->startRow = $startRow;
                    $this->endRow = $endRow;
                }

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row >= $this->startRow
                        && $row <= $this->endRow
                        && Coordinate::columnIndexFromString($columnAddress) <= $this->maximumColumns;
                }
            };

            $reader->setReadFilter($filter);

            for ($chunkStart = $startRow; $chunkStart <= $totalRows; $chunkStart += $chunkSize) {
                $chunkEnd = min($totalRows, $chunkStart + $chunkSize - 1);
                $chunkRows = [];
                $spreadsheet = null;
                $filter->setRows($chunkStart, $chunkEnd);

                try {
                    $spreadsheet = $reader->load($path);

                    if ($spreadsheet->getSheetCount() < 1) {
                        throw new InvalidArgumentException('The selected worksheet could not be loaded.');
                    }

                    $worksheet = $spreadsheet->getSheet(0);
                    $lastColumn = Coordinate::stringFromColumnIndex($totalColumns);
                    $matrix = $worksheet->rangeToArray(
                        "A{$chunkStart}:{$lastColumn}{$chunkEnd}",
                        null,
                        false,
                        false,
                        false,
                    );

                    foreach (array_values($matrix) as $offset => $rowValues) {
                        $values = [];
                        $hasContent = false;

                        foreach (array_values($rowValues) as $columnOffset => $value) {
                            if (is_string($value) && mb_strlen($value, 'UTF-8') > $limits['max_cell_length']) {
                                throw new InvalidArgumentException('A worksheet cell exceeds the configured text length limit.');
                            }

                            $values[$columnOffset + 1] = $value;

                            if ($value !== null && $value !== '') {
                                $hasContent = true;
                            }
                        }

                        if ($hasContent) {
                            $chunkRows[] = [
                                'row_number' => $chunkStart + $offset,
                                'values' => $values,
                            ];
                        }
                    }
                } catch (SpreadsheetException $exception) {
                    throw new InvalidArgumentException('The selected worksheet could not be read safely.', 0, $exception);
                } finally {
                    $spreadsheet?->disconnectWorksheets();
                    unset($spreadsheet);
                }

                foreach ($chunkRows as $row) {
                    yield $row;
                }
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * Return a bounded sample from one worksheet and optionally identify a one-based source row as its header.
     * The header is explicit; a null headerRow means the source has no header row.
     *
     * @return array{
     *     sheet_index: int,
     *     sheet_name: string,
     *     sample_start_row: int,
     *     sample_end_row: int,
     *     header_row: ?int,
     *     headers: ?array<int, mixed>,
     *     rows: list<array{row_number: int, values: array<int, mixed>}>
     * }
     */
    public function preview(
        ImportSession $session,
        int $sheetIndex,
        int $startRow = 1,
        ?int $sampleRows = null,
        ?int $headerRow = null,
        ?string $csvDelimiter = null,
        string $csvEncoding = Csv::GUESS_ENCODING,
    ): array {
        if ($sheetIndex < 0 || $startRow < 1) {
            throw new InvalidArgumentException('The worksheet index cannot be negative and preview starting rows must be positive.');
        }

        $limits = $this->limits();
        $sampleRows ??= $limits['preview_rows'];

        if ($sampleRows < 1 || $sampleRows > $limits['max_preview_rows']) {
            throw new InvalidArgumentException(sprintf(
                'The preview sample must contain between 1 and %d source rows.',
                $limits['max_preview_rows'],
            ));
        }

        if ($startRow > $limits['max_rows']) {
            throw new InvalidArgumentException('The preview starting row exceeds the configured worksheet row limit.');
        }

        $sampleEndRow = $startRow + min($sampleRows - 1, $limits['max_rows'] - $startRow);

        if ($headerRow !== null && ($headerRow < $startRow || $headerRow > $sampleEndRow)) {
            throw new InvalidArgumentException('The selected header row must be inside the preview sample window.');
        }

        $sheets = $this->listSheets($session, $csvDelimiter, $csvEncoding);

        if (! array_key_exists($sheetIndex, $sheets)) {
            throw new InvalidArgumentException('The selected worksheet does not exist in this file.');
        }

        $rows = [];

        foreach ($this->readRows($session, $sheetIndex, $startRow, $csvDelimiter, $csvEncoding) as $row) {
            if ($row['row_number'] > $sampleEndRow) {
                break;
            }

            $rows[] = $row;
        }

        $headers = null;

        if ($headerRow !== null) {
            foreach ($rows as $row) {
                if ($row['row_number'] === $headerRow) {
                    $headers = $row['values'];
                    break;
                }
            }

            if ($headers === null) {
                throw new InvalidArgumentException('The selected header row is empty or unavailable in the preview sample.');
            }
        }

        return [
            'sheet_index' => $sheetIndex,
            'sheet_name' => $sheets[$sheetIndex]['name'],
            'sample_start_row' => $startRow,
            'sample_end_row' => $sampleEndRow,
            'header_row' => $headerRow,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{chunk_size: int, max_rows: int, max_columns: int, max_sheets: int, max_cell_length: int, max_file_size_bytes: int, max_uncompressed_bytes: int, preview_rows: int, max_preview_rows: int}
     */
    private function limits(): array
    {
        $limits = [
            'chunk_size' => (int) config('smart-import.reading.chunk_size', 250),
            'max_rows' => (int) config('smart-import.reading.max_rows', 100000),
            'max_columns' => (int) config('smart-import.reading.max_columns', 256),
            'max_sheets' => (int) config('smart-import.reading.max_sheets', 20),
            'max_cell_length' => (int) config('smart-import.reading.max_cell_length', 32767),
            'max_file_size_bytes' => (int) config('smart-import.uploads.max_file_size_bytes', 10 * 1024 * 1024),
            'max_uncompressed_bytes' => (int) config('smart-import.reading.max_uncompressed_bytes', 32 * 1024 * 1024),
            'preview_rows' => (int) config('smart-import.reading.preview_rows', 20),
            'max_preview_rows' => (int) config('smart-import.reading.max_preview_rows', 100),
        ];

        foreach ($limits as $name => $value) {
            if ($value < 1) {
                throw new RuntimeException("The Smart Import [{$name}] reading limit must be greater than zero.");
            }
        }

        if ($limits['chunk_size'] > self::MAX_CHUNK_SIZE) {
            throw new RuntimeException(sprintf(
                'The Smart Import reading chunk size cannot exceed %d rows.',
                self::MAX_CHUNK_SIZE,
            ));
        }

        if ($limits['max_preview_rows'] > self::MAX_PREVIEW_ROWS || $limits['preview_rows'] > $limits['max_preview_rows']) {
            throw new RuntimeException(sprintf(
                'The Smart Import preview sample limit must not exceed %d rows, and the default must not exceed that limit.',
                self::MAX_PREVIEW_ROWS,
            ));
        }

        return $limits;
    }

    private function materializeSource(ImportSession $session, array $limits): string
    {
        $expectedSize = (int) $session->file_size;

        if ($expectedSize < 1 || $expectedSize > $limits['max_file_size_bytes']) {
            throw new InvalidArgumentException('The stored import source is empty or exceeds the configured size limit.');
        }

        $fileType = strtolower((string) $session->file_type);

        if (! isset(self::READERS[$fileType])) {
            throw new InvalidArgumentException('The stored import source has an unsupported file type.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'smart-import-');

        if (! is_string($temporaryPath)) {
            throw new RuntimeException('A private temporary file could not be created for reading.');
        }

        $sourceStream = null;
        $targetStream = null;

        try {
            try {
                $sourcePath = $session->claimed_storage_path ?: $session->storage_path;
                $sourceStream = Storage::disk($session->storage_disk)->readStream($sourcePath);
            } catch (Throwable $exception) {
                throw new RuntimeException('The stored import source is unavailable.', 0, $exception);
            }

            if (! is_resource($sourceStream)) {
                throw new RuntimeException('The stored import source is unavailable.');
            }

            $targetStream = fopen($temporaryPath, 'wb');

            if (! is_resource($targetStream)) {
                throw new RuntimeException('A private temporary file could not be opened for reading.');
            }

            $copiedBytes = stream_copy_to_stream($sourceStream, $targetStream, $limits['max_file_size_bytes'] + 1);
            fflush($targetStream);

            if (! is_int($copiedBytes) || $copiedBytes > $limits['max_file_size_bytes']) {
                throw new InvalidArgumentException('The stored import source exceeds the configured size limit.');
            }

            if ($copiedBytes !== $expectedSize) {
                throw new RuntimeException('The stored import source size no longer matches its registered identity.');
            }

            fclose($targetStream);
            $targetStream = null;

            $hash = hash_file('sha256', $temporaryPath);

            if (! is_string($hash) || ! hash_equals(strtolower((string) $session->file_hash), $hash)) {
                throw new RuntimeException('The stored import source checksum no longer matches its registered identity.');
            }

            if ($fileType === 'xlsx') {
                $this->assertUncompressedSize($temporaryPath, $limits['max_uncompressed_bytes']);
            }

            try {
                $reader = IOFactory::identify($temporaryPath, [self::READERS[$fileType]]);
            } catch (ReaderException $exception) {
                throw new InvalidArgumentException('The stored import source is not a readable spreadsheet file.', 0, $exception);
            }

            if ($reader !== self::READERS[$fileType]) {
                throw new InvalidArgumentException('The stored import source format does not match its registered type.');
            }

            return $temporaryPath;
        } catch (Throwable $exception) {
            @unlink($temporaryPath);

            throw $exception;
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }

            if (is_resource($targetStream)) {
                fclose($targetStream);
            }
        }
    }

    private function createReader(
        ImportSession $session,
        ?string $csvDelimiter = null,
        string $csvEncoding = Csv::GUESS_ENCODING,
    ): object
    {
        $fileType = strtolower((string) $session->file_type);

        if (! isset(self::READERS[$fileType])) {
            throw new InvalidArgumentException('The stored import source has an unsupported file type.');
        }

        $reader = IOFactory::createReader(self::READERS[$fileType]);

        if ($reader instanceof Csv) {
            $this->validateCsvOptions($csvDelimiter, $csvEncoding);
            $reader->setDelimiter($csvDelimiter);
            $reader->setInputEncoding($csvEncoding);
        }

        return $reader;
    }

    private function validateCsvOptions(?string $delimiter, string $encoding): void
    {
        if ($delimiter !== null && (strlen($delimiter) !== 1 || in_array($delimiter, ["\0", "\r", "\n", '"'], true))) {
            throw new InvalidArgumentException('A CSV delimiter must be one safe, single-byte character.');
        }

        if ($encoding === Csv::GUESS_ENCODING) {
            return;
        }

        if ($encoding === '' || strlen($encoding) > 40 || preg_match('/^[A-Za-z0-9._-]+$/D', $encoding) !== 1) {
            throw new InvalidArgumentException('The requested CSV input encoding is invalid.');
        }

        try {
            mb_convert_encoding('Smart Import', 'UTF-8', $encoding);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The requested CSV input encoding is not supported.', 0, $exception);
        }
    }

    private function worksheetInfo(object $reader, string $path, array $limits): array
    {
        try {
            $worksheets = array_values($reader->listWorksheetInfo($path));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Worksheet metadata could not be read safely.', 0, $exception);
        }

        if ($worksheets === []) {
            throw new InvalidArgumentException('The file does not contain a readable worksheet.');
        }

        if (count($worksheets) > $limits['max_sheets']) {
            throw new InvalidArgumentException('The workbook exceeds the configured worksheet limit.');
        }

        return $worksheets;
    }

    private function assertWorksheetDimensions(array $worksheetInfo, array $limits): void
    {
        if ((int) ($worksheetInfo['totalRows'] ?? 0) > $limits['max_rows']) {
            throw new InvalidArgumentException('The selected worksheet exceeds the configured row limit.');
        }

        if ((int) ($worksheetInfo['totalColumns'] ?? 0) > $limits['max_columns']) {
            throw new InvalidArgumentException('The selected worksheet exceeds the configured column limit.');
        }
    }

    private function assertUncompressedSize(string $path, int $maximumBytes): void
    {
        $archive = new ZipArchive();
        $opened = $archive->open($path, ZipArchive::RDONLY);

        if ($opened !== true) {
            throw new InvalidArgumentException('The XLSX archive could not be opened safely.');
        }

        try {
            $totalBytes = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);

                if (! is_array($entry) || ! isset($entry['size'])) {
                    throw new InvalidArgumentException('The XLSX archive contains an unreadable entry.');
                }

                $entrySize = (int) $entry['size'];

                if ($entrySize < 0 || $entrySize > $maximumBytes - $totalBytes) {
                    throw new InvalidArgumentException('The XLSX archive exceeds the configured expanded-size limit.');
                }

                $totalBytes += $entrySize;
            }
        } finally {
            $archive->close();
        }
    }
}
