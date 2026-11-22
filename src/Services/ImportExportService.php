<?php

namespace SmartImport\Services;

use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use SmartImport\Data\ImportField;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportOutboxEvent;
use SmartImport\Models\ImportSession;
use Throwable;

final class ImportExportService
{
    public function __construct(
        private ImportAuthorization $authorization,
        private ImportCoordinator $coordinator,
        private ImporterRegistry $importers,
        private ImportPayloadCodec $payloadCodec,
    ) {}

    /** Stream a canonical-header CSV template for one authorized importer. */
    public function templateCsv(string $importerKey, Authenticatable $actor): Generator
    {
        $definition = $this->importers->get($importerKey);
        $this->authorization->authorize('useImporter', $actor, $definition);
        $headers = array_map(fn (ImportField $field): string => $this->fieldText($field->name), $definition->fields());

        return $this->csvLines([$headers]);
    }

    /** Return an XLSX template with canonical import headers and a field guide sheet. */
    public function templateXlsx(string $importerKey, Authenticatable $actor): string
    {
        $definition = $this->importers->get($importerKey);
        $this->authorization->authorize('useImporter', $actor, $definition);
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setTitle('Smart Import Template');
        $importSheet = $spreadsheet->getActiveSheet();
        $importSheet->setTitle('Import Data');

        foreach ($definition->fields() as $offset => $field) {
            $cell = Coordinate::stringFromColumnIndex($offset + 1).'1';
            $importSheet->setCellValueExplicit($cell, $this->fieldText($field->name), DataType::TYPE_STRING);
        }

        $guideSheet = $spreadsheet->createSheet();
        $guideSheet->setTitle('Field Guide');

        foreach (['Field', 'Label', 'Type', 'Required'] as $offset => $header) {
            $guideSheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($offset + 1).'1',
                $header,
                DataType::TYPE_STRING,
            );
        }

        foreach ($definition->fields() as $rowOffset => $field) {
            $values = [
                $field->name,
                $field->label,
                $field->type,
                $field->required ? 'Yes' : 'No',
            ];

            foreach ($values as $columnOffset => $value) {
                $guideSheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($columnOffset + 1).($rowOffset + 2),
                    $this->fieldText($value),
                    DataType::TYPE_STRING,
                );
            }
        }

        $bufferLevel = ob_get_level();
        ob_start();

        try {
            (new Xlsx($spreadsheet))->save('php://output');
            $contents = ob_get_clean();

            if (! is_string($contents)) {
                throw new RuntimeException('The XLSX template could not be generated.');
            }

            return $contents;
        } catch (Throwable $exception) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            throw new RuntimeException('The XLSX template could not be generated safely.', 0, $exception);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * Stream field-level errors for an authorized session. Each invalid cell becomes one CSV row.
     */
    public function validationErrorsCsv(string $sessionId, Authenticatable $actor): Generator
    {
        $session = $this->coordinator->get($sessionId, $actor);
        $this->authorization->authorize('export', $actor, $session);
        $fieldAccess = [];

        if ($session->importer_key !== null && $this->importers->has($session->importer_key)) {
            foreach ($this->importers->get($session->importer_key)->fields() as $field) {
                if (! $field instanceof ImportField) {
                    continue;
                }

                $fieldAccess[$field->name] = ! $field->sensitive || $this->authorization->allows(
                    'viewSensitiveImportFields',
                    $actor,
                    [$session, $field],
                );
            }
        }

        return $this->errorLines($session, $fieldAccess);
    }

    /** Stream safe metadata and persisted counters for one authorized import. */
    public function summaryCsv(string $sessionId, Authenticatable $actor): Generator
    {
        $session = $this->coordinator->get($sessionId, $actor);
        $this->authorization->authorize('export', $actor, $session);
        $duration = $session->started_at === null
            ? null
            : max(0, ($session->finished_at ?? now())->getTimestamp() - $session->started_at->getTimestamp());
        $outboxStatus = ImportOutboxEvent::query()
            ->where('import_id', $session->getKey())
            ->orderByDesc('id')
            ->value('delivery_status');
        $unchanged = $session->rows()->where('execution_status', 'skipped')->where('last_error_code', 'unchanged')->count();
        $excluded = $session->rows()->where('execution_status', 'excluded')->count();
        $snapshotConflicts = $session->rows()->where('execution_status', 'failed')->where('last_error_code', 'target_changed_after_preview')->count();
        $summary = [
            ['import_id', (string) $session->getKey()],
            ['filename', $session->original_filename],
            ['source', $session->source],
            ['status', $session->status?->value ?? 'unassigned'],
            ['importer', $session->importer_key],
            ['importer_version', $session->importer_version],
            ['target_model', $session->target_model],
            ['received_at', $session->created_at?->toIso8601String()],
            ['started_at', $session->started_at?->toIso8601String()],
            ['finished_at', $session->finished_at?->toIso8601String()],
            ['duration_seconds', $duration],
            ['rows_in_source', (int) $session->stage_total],
            ['rows_checked', (int) $session->stage_processed],
            ['valid_rows', (int) $session->stage_valid],
            ['invalid_rows', (int) $session->stage_invalid],
            ['warning_rows', (int) $session->stage_warning],
            ['execution_rows', (int) $session->execution_total],
            ['processed_rows', (int) $session->processed],
            ['created', (int) $session->created_count],
            ['updated', (int) $session->updated_count],
            ['skipped', (int) $session->skipped_count],
            ['failed', (int) $session->failed_count],
            ['unchanged', $unchanged],
            ['excluded', $excluded],
            ['snapshot_conflicts', $snapshotConflicts],
            ['not_processed', max(0, (int) $session->execution_total - (int) $session->processed)],
            ['cancelled', $session->status === ImportStatus::Cancelled ? 1 : 0],
            ['attempts', count($session->attempt_history ?? [])],
            ['notification_delivery_status', is_string($outboxStatus) ? $outboxStatus : 'none'],
        ];

        return $this->csvLines([['metric', 'value'], ...$summary]);
    }

    /** @param iterable<list<mixed>> $rows */
    private function csvLines(iterable $rows): Generator
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('A temporary CSV stream could not be opened.');
        }

        try {
            foreach ($rows as $row) {
                rewind($stream);
                ftruncate($stream, 0);
                $cells = array_map(fn (mixed $value): string => $this->safeCsvCell($value), $row);

                if (fputcsv($stream, $cells, ',', '"', '', "\r\n") === false) {
                    throw new RuntimeException('A CSV row could not be encoded.');
                }

                rewind($stream);
                $line = stream_get_contents($stream);

                if (! is_string($line)) {
                    throw new RuntimeException('A CSV row could not be read from its temporary stream.');
                }

                yield $line;
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, bool> $fieldAccess */
    private function errorLines(ImportSession $session, array $fieldAccess): Generator
    {
        $headers = ['source_row', 'source_sheet', 'field', 'code', 'safe_message', 'value'];
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('A temporary CSV stream could not be opened.');
        }

        try {
            yield $this->csvLine($stream, $headers);

            foreach (
                $session->rows()
                    ->where(static function ($query): void {
                        $query->where('validation_status', 'invalid')
                            ->orWhere('execution_status', 'failed');
                    })
                    ->orderBy('source_row_number')
                    ->orderBy('id')
                    ->cursor() as $row
            ) {
                foreach ($row->validation_errors ?? [] as $error) {
                    if (! is_array($error)) {
                        continue;
                    }

                    $field = is_string($error['field'] ?? null) ? $error['field'] : '';
                    $mappedValues = $this->payloadCodec->reveal(
                        $row->mapped_values,
                        array_keys(array_filter($fieldAccess)),
                    );
                    $value = $field !== ''
                        && ($fieldAccess[$field] ?? false)
                        && array_key_exists($field, $mappedValues)
                            ? $mappedValues[$field]
                            : null;
                    $code = $this->safeReportCode($error['code'] ?? null);

                    yield $this->csvLine($stream, [
                        $row->source_row_number,
                        $row->source_sheet,
                        $field,
                        $code,
                        $this->safeFailureMessage($code),
                        $value,
                    ]);
                }

                if ($row->execution_status === 'failed') {
                    $code = $this->safeReportCode($row->last_error_code);

                    yield $this->csvLine($stream, [
                        $row->source_row_number,
                        $row->source_sheet,
                        '',
                        $code,
                        'The row could not be processed under the confirmed import rules.',
                        '',
                    ]);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @param list<mixed> $row */
    private function csvLine($stream, array $row): string
    {
        rewind($stream);
        ftruncate($stream, 0);
        $cells = array_map(fn (mixed $value): string => $this->safeCsvCell($value), $row);

        if (fputcsv($stream, $cells, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('A CSV row could not be encoded.');
        }

        rewind($stream);
        $line = stream_get_contents($stream);

        if (! is_string($line)) {
            throw new RuntimeException('A CSV row could not be read from its temporary stream.');
        }

        return $line;
    }

    private function safeCsvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            $cell = $value ? '1' : '0';
        } elseif (is_int($value) || is_string($value)) {
            $cell = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $cell = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } else {
            throw new InvalidArgumentException('CSV export cells must be scalar or null.');
        }

        $formulaLike = preg_match('/^[\x00-\x20]*[=+\-@]/', $cell) === 1
            || preg_match('/^[\t\r\n]/', $cell) === 1;

        if (! $formulaLike && mb_check_encoding($cell, 'UTF-8')) {
            $formulaLike = preg_match('/^[\p{Z}\s\p{Cf}]*[=+\-@]/u', $cell) === 1;
        }

        return $formulaLike ? "'".$cell : $cell;
    }

    private function safeReportCode(mixed $code): string
    {
        return is_string($code) && preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D', $code) === 1
            ? $code
            : 'validation';
    }

    private function safeFailureMessage(string $code): string
    {
        return match ($code) {
            'required' => 'A required field is missing.',
            'email' => 'The value is not a valid email address.',
            'transform_failed' => 'The value could not be transformed for this field.',
            'duplicate_in_file' => 'This value is part of a duplicate key in another file row.',
            'relationship_value_invalid' => 'The relationship reference must be a text or number value.',
            'relationship_not_found' => 'No related record matches this value.',
            'relationship_ambiguous' => 'More than one related record matches this value.',
            'target_match_ambiguous' => 'The configured matching key identifies more than one target record.',
            default => 'This field did not pass the configured validation rules.',
        };
    }

    private function fieldText(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 32767) {
            throw new InvalidArgumentException('Importer template fields must be valid UTF-8 and fit in one spreadsheet cell.');
        }

        return $value;
    }
}
