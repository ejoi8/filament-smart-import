<?php

namespace SmartImport\Services;

use Generator;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Csv\Delimiter;
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
            if ($reader instanceof Csv) {
                $this->primeCsvReader($reader, $path);
            }
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

    /** @return array{csv_delimiter: ?string, csv_encoding: string} */
    public function normalizeCsvOptions(?string $delimiter = null, string $encoding = Csv::GUESS_ENCODING): array
    {
        $encoding = $this->normalizeEncoding($encoding);
        $this->validateCsvOptions($delimiter, $encoding);

        return [
            'csv_delimiter' => $delimiter,
            'csv_encoding' => $encoding,
        ];
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
            if ($reader instanceof Csv) {
                $this->primeCsvReader($reader, $path);
            }
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

            $sheetName = (string) $worksheetInfo['worksheetName'];

            if ($reader instanceof Csv) {
                yield from $this->readCsvRows($reader, $path, $startRow, $totalRows, $totalColumns, $limits['max_cell_length']);

                return;
            }

            $reader->setLoadSheetsOnly([$sheetName]);
            $reader->setReadEmptyCells(false);

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
     * Stream CSV records after PhpSpreadsheet has validated dimensions and selected delimiter/encoding.
     *
     * @return Generator<int, array{row_number: int, values: array<int, mixed>}>
     */
    private function readCsvRows(
        Csv $reader,
        string $path,
        int $startRow,
        int $totalRows,
        int $totalColumns,
        int $maxCellLength,
    ): Generator {
        $stream = fopen($path, 'rb');

        if (! is_resource($stream)) {
            throw new RuntimeException('The stored CSV source could not be opened for reading.');
        }

        try {
            $this->skipCsvByteOrderMark($stream);
            $encoding = $reader->getInputEncoding();

            if (strcasecmp($encoding, 'UTF-8') !== 0 && strcasecmp($encoding, 'UTF8') !== 0) {
                $filter = @stream_filter_append($stream, 'convert.iconv.'.$encoding.'/UTF-8', STREAM_FILTER_READ);

                if ($filter === false) {
                    throw new InvalidArgumentException('The selected CSV input encoding could not be streamed safely.');
                }
            }

            $delimiter = $reader->getDelimiter() ?? ',';
            $enclosure = $reader->getEnclosure();
            $escape = $reader->getEscapeCharacter();
            $sourceRow = 0;

            while (($row = fgetcsv($stream, null, $delimiter, $enclosure, $escape)) !== false) {
                // PhpSpreadsheet treats a leading sep=X directive as metadata rather than a worksheet row.
                if ($sourceRow === 0 && count($row) === 2 && $row[0] === 'sep=' && $row[1] === '') {
                    continue;
                }

                $sourceRow++;

                if ($sourceRow < $startRow) {
                    continue;
                }

                if ($sourceRow > $totalRows) {
                    break;
                }

                if (count($row) > $totalColumns) {
                    throw new InvalidArgumentException('The selected worksheet exceeds the configured column limit.');
                }

                $values = array_fill(1, $totalColumns, null);
                $hasContent = false;

                foreach ($row as $offset => $value) {
                    if (is_string($value) && mb_strlen($value, 'UTF-8') > $maxCellLength) {
                        throw new InvalidArgumentException('A worksheet cell exceeds the configured text length limit.');
                    }

                    $values[$offset + 1] = $value;
                    $hasContent = $hasContent || ($value !== null && $value !== '');
                }

                if ($hasContent) {
                    yield [
                        'row_number' => $sourceRow,
                        'values' => $values,
                    ];
                }
            }
        } catch (SpreadsheetException $exception) {
            throw new InvalidArgumentException('The selected worksheet could not be read safely.', 0, $exception);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function skipCsvByteOrderMark($stream): void
    {
        $prefix = fread($stream, 4);

        if (! is_string($prefix)) {
            throw new RuntimeException('The stored CSV source could not be inspected safely.');
        }

        $bomLength = $this->csvByteOrderMarkLength($prefix);

        if (fseek($stream, $bomLength) !== 0) {
            throw new RuntimeException('The stored CSV source could not be positioned safely.');
        }
    }

    private function csvByteOrderMarkLength(string $prefix): int
    {
        return match (true) {
            str_starts_with($prefix, "\x00\x00\xFE\xFF"),
            str_starts_with($prefix, "\xFF\xFE\x00\x00") => 4,
            str_starts_with($prefix, "\xEF\xBB\xBF") => 3,
            str_starts_with($prefix, "\xFE\xFF"),
            str_starts_with($prefix, "\xFF\xFE") => 2,
            default => 0,
        };
    }

    private function primeCsvReader(Csv $reader, string $path): void
    {
        if ($reader->getDelimiter() !== null) {
            return;
        }

        $sample = file_get_contents($path, false, null, 0, 65536);

        if (! is_string($sample)) {
            throw new RuntimeException('The stored CSV source could not be sampled for delimiter detection.');
        }

        $encoding = $reader->getInputEncoding();

        if ($encoding === Csv::GUESS_ENCODING) {
            $encoding = Csv::guessEncoding($path);
        } elseif (strcasecmp($encoding, 'UTF-8') === 0) {
            $bomEncoding = Csv::guessEncodingBom($path);
            $encoding = $bomEncoding !== '' ? $bomEncoding : $encoding;
        }

        $sample = substr($sample, $this->csvByteOrderMarkLength(substr($sample, 0, 4)));

        if (strcasecmp($encoding, 'UTF-8') !== 0 && strcasecmp($encoding, 'UTF8') !== 0) {
            $sample = mb_convert_encoding($sample, 'UTF-8', $encoding);
        }

        if (preg_match('/\Asep=([^\r\n])(?:\r\n|\n|\r|\z)/i', $sample, $separator) === 1) {
            $reader->setDelimiter($separator[1]);

            return;
        }

        $sampleStream = fopen('php://temp', 'w+b');

        if (! is_resource($sampleStream)) {
            throw new RuntimeException('A bounded CSV delimiter sample could not be opened.');
        }

        try {
            fwrite($sampleStream, $sample);
            rewind($sampleStream);
            $detector = new Delimiter($sampleStream, $reader->getEscapeCharacter(), $reader->getEnclosure());
            $reader->setDelimiter($detector->infer() ?? $detector->getDefaultDelimiter());
        } finally {
            fclose($sampleStream);
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
            $options = $this->normalizeCsvOptions($csvDelimiter, $csvEncoding);
            $reader->setDelimiter($options['csv_delimiter']);
            $reader->setInputEncoding($options['csv_encoding']);
        }

        return $reader;
    }

    private function validateCsvOptions(?string $delimiter, string $encoding): void
    {
        if ($delimiter !== null && (strlen($delimiter) !== 1 || in_array($delimiter, ["\0", "\r", "\n", '"'], true))) {
            throw new InvalidArgumentException('A CSV delimiter must be one safe, single-byte character.');
        }

        $configuredDelimiters = config('smart-import.reading.csv_delimiters', []);

        if (! is_array($configuredDelimiters)) {
            throw new InvalidArgumentException('The configured CSV delimiter allowlist must be an array.');
        }

        $allowedDelimiters = array_map(
            static fn (string|int $configured): string => $configured === 'tab' ? "\t" : (string) $configured,
            array_keys($configuredDelimiters),
        );

        if ($delimiter !== null && ! in_array($delimiter, $allowedDelimiters, true)) {
            throw new InvalidArgumentException('The selected CSV delimiter is not enabled by package configuration.');
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

        $configuredEncodings = config('smart-import.reading.csv_encodings', []);

        if (! is_array($configuredEncodings) || ! array_is_list($configuredEncodings)) {
            throw new InvalidArgumentException('The configured CSV encoding allowlist must be a list.');
        }

        $preferredEncoding = mb_preferred_mime_name($encoding);
        $allowed = false;

        foreach ($configuredEncodings as $configuredEncoding) {
            if (! is_string($configuredEncoding)) {
                throw new InvalidArgumentException('The configured CSV encoding allowlist must contain strings.');
            }

            try {
                $configuredPreferredEncoding = mb_preferred_mime_name($configuredEncoding);
            } catch (Throwable $exception) {
                throw new InvalidArgumentException('The configured CSV encoding allowlist contains an unsupported encoding.', 0, $exception);
            }

            if (strcasecmp($configuredPreferredEncoding, $preferredEncoding) === 0) {
                $allowed = true;

                break;
            }
        }

        if (! $allowed) {
            throw new InvalidArgumentException('The selected CSV input encoding is not enabled by package configuration.');
        }
    }

    private function normalizeEncoding(string $encoding): string
    {
        if ($encoding === Csv::GUESS_ENCODING) {
            return $encoding;
        }

        $configuredEncodings = config('smart-import.reading.csv_encodings', []);

        if (! is_array($configuredEncodings) || ! array_is_list($configuredEncodings)) {
            throw new InvalidArgumentException('The configured CSV encoding allowlist must be a list.');
        }

        try {
            $preferredEncoding = mb_preferred_mime_name($encoding);
        } catch (Throwable) {
            return $encoding;
        }

        foreach ($configuredEncodings as $configuredEncoding) {
            if (! is_string($configuredEncoding)) {
                throw new InvalidArgumentException('The configured CSV encoding allowlist must contain strings.');
            }

            try {
                if (strcasecmp(mb_preferred_mime_name($configuredEncoding), $preferredEncoding) === 0) {
                    return $configuredEncoding;
                }
            } catch (Throwable $exception) {
                throw new InvalidArgumentException('The configured CSV encoding allowlist contains an unsupported encoding.', 0, $exception);
            }
        }

        return $encoding;
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
