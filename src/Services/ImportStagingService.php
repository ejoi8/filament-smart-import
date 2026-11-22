<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportExecutionContext;
use SmartImport\Enums\ImportStatus;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use Throwable;

final class ImportStagingService
{
    public function __construct(
        private ImportCoordinator $coordinator,
        private ImportAuthorization $authorization,
        private ImporterRegistry $importers,
        private SpreadsheetReader $reader,
        private ColumnMapper $columnMapper,
        private RowDataProcessor $rowDataProcessor,
        private TargetRecordMatcher $targetRecordMatcher,
        private StagedRowService $stagedRows,
        private ImportExecutionDispatcher $executionDispatcher,
        private ImportOptionResolver $importOptions,
        private ImportExecutionContext $executionContext,
        private ImportPayloadCodec $payloadCodec,
        private ImportIndexHasher $indexHasher,
        private ImportOutboxService $outbox,
        private ImportAuditService $audit,
    ) {}

    /**
     * Freeze a selected importer, sheet, and complete mapping, then queue staging.
     *
     * @param array<int, string|null> $mapping One-based source column indexes to fields or explicit null ignores.
     */
    public function start(
        string $sessionId,
        Authenticatable $actor,
        string $importerKey,
        int $sheetIndex,
        ?int $headerRow,
        array $mapping,
        array $options = [],
    ): ImportSession {
        $session = $this->coordinator->get($sessionId, $actor);
        $definition = $this->importers->get($importerKey);
        $this->authorization->authorize('useImporter', $actor, $definition);
        $unknownOptions = array_diff(array_keys($options), ['csv_delimiter', 'csv_encoding', 'importer_options']);

        if ($unknownOptions !== []) {
            throw new InvalidArgumentException('Unsupported Smart Import reader option(s) were supplied.');
        }

        $csvDelimiter = $options['csv_delimiter'] ?? null;
        $csvEncoding = $options['csv_encoding'] ?? Csv::GUESS_ENCODING;

        if (($csvDelimiter !== null && ! is_string($csvDelimiter)) || ! is_string($csvEncoding)) {
            throw new InvalidArgumentException('CSV delimiter and encoding settings must be strings.');
        }

        $requestedImporterOptions = $options['importer_options'] ?? [];

        if (! is_array($requestedImporterOptions)) {
            throw new InvalidArgumentException('Importer options must be provided as a key/value map.');
        }

        $resolvedImporterOptions = $this->importOptions->resolve($definition, $requestedImporterOptions);
        $this->indexHasher->hash('', ImportIndexHasher::CURRENT_VERSION);
        $readerOptions = $this->reader->normalizeCsvOptions($csvDelimiter, $csvEncoding);
        $sheets = $this->reader->listSheets($session, $readerOptions['csv_delimiter'], $readerOptions['csv_encoding']);

        if (! array_key_exists($sheetIndex, $sheets)) {
            throw new InvalidArgumentException('The selected worksheet does not exist in this file.');
        }

        $sheet = $sheets[$sheetIndex];
        $columnCount = $sheet['columns'];

        if ($columnCount < 1) {
            throw new InvalidArgumentException('The selected worksheet does not contain any mappable columns.');
        }

        if ($headerRow === null) {
            $headers = array_fill_keys(range(1, $columnCount), null);
        } else {
            if ($headerRow > $sheet['rows']) {
                throw new InvalidArgumentException('The selected header row is outside the worksheet.');
            }

            $preview = $this->reader->preview(
                $session,
                $sheetIndex,
                $headerRow,
                1,
                $headerRow,
                $readerOptions['csv_delimiter'],
                $readerOptions['csv_encoding'],
            );
            $headers = $preview['headers'];

            if ($headers === null) {
                throw new InvalidArgumentException('The selected header row could not be read.');
            }
        }

        $validated = $this->columnMapper->validateManualMapping($definition, $headers, $mapping);
        $completeMapping = $validated['mapping'];

        foreach ($validated['ignored_columns'] as $sourceColumn) {
            $completeMapping[$sourceColumn] = null;
        }

        ksort($completeMapping, SORT_NUMERIC);

        $this->executionDispatcher->assertQueueCanRunAsynchronously();

        $dataStartRow = $headerRow === null ? 1 : $headerRow + 1;
        $estimatedTotal = max(0, $sheet['rows'] - $dataStartRow + 1);
        $stagingSession = $this->coordinator->beginStaging(
            $sessionId,
            $importerKey,
            $sheetIndex,
            $sheet['name'],
            $headerRow,
            $completeMapping,
            $estimatedTotal,
            $actor,
            [
                'reader' => $readerOptions,
                'importer' => $resolvedImporterOptions,
                'security' => ['index_hash_version' => ImportIndexHasher::CURRENT_VERSION],
            ],
        );

        $job = new StageImportJob(
            (string) $stagingSession->getKey(),
            $stagingSession->mapping_revision,
            $stagingSession->staging_revision,
        );
        $queueName = config('smart-import.queue.name');
        $queueConnection = config('smart-import.queue.connection');

        if (is_string($queueName) && $queueName !== '') {
            $job->onQueue($queueName);
        }

        if (is_string($queueConnection) && $queueConnection !== '') {
            $job->onConnection($queueConnection);
        }

        $job->afterCommit();

        try {
            dispatch($job);
        } catch (Throwable $exception) {
            $this->fail($sessionId, $stagingSession->mapping_revision, $stagingSession->staging_revision);

            throw $exception;
        }

        return $stagingSession->fresh() ?? $stagingSession;
    }

    /** Process the one immutable staging revision referenced by the queued job. */
    public function stage(string $sessionId, int $mappingRevision, int $stagingRevision): void
    {
        $session = ImportSession::query()->find($sessionId);

        if ($session === null) {
            return;
        }

        $this->executionContext->run($session, fn () => $this->stageWithinContext($sessionId, $mappingRevision, $stagingRevision));
    }

    private function stageWithinContext(string $sessionId, int $mappingRevision, int $stagingRevision): void
    {
        $session = $this->currentSession($sessionId, $mappingRevision, $stagingRevision);

        if ($session === null) {
            return;
        }

        if (
            $session->importer_key === null
            || $session->importer_version === null
            || $session->sheet_index === null
            || $session->sheet_name === null
            || $session->mapping === null
        ) {
            throw new InvalidArgumentException('The staged import configuration is incomplete.');
        }

        $definition = $this->importers->get($session->importer_key);

        if ($definition->version() !== $session->importer_version) {
            throw new InvalidArgumentException('The importer definition changed after staging was queued.');
        }

        $storedOptions = $session->options ?? [];
        $storedReaderOptions = $storedOptions['reader'] ?? [];
        $storedImporterOptions = $storedOptions['importer'] ?? [];
        $storedSecurityOptions = $storedOptions['security'] ?? ['index_hash_version' => 1];

        if (! is_array($storedReaderOptions) || ! is_array($storedImporterOptions)
            || ! is_array($storedSecurityOptions)
            || array_diff(array_keys($storedSecurityOptions), ['index_hash_version']) !== []
            || ! in_array($storedSecurityOptions['index_hash_version'] ?? null, [1, 2], true)) {
            throw new InvalidArgumentException('The saved staging options are incomplete.');
        }

        $readerOptions = $this->reader->normalizeCsvOptions(
            $storedReaderOptions['csv_delimiter'] ?? null,
            $storedReaderOptions['csv_encoding'] ?? Csv::GUESS_ENCODING,
        );

        if ((array_key_exists('reader', $storedOptions) && $readerOptions !== $storedReaderOptions)
            || (array_key_exists('security', $storedOptions) && $storedSecurityOptions !== ['index_hash_version' => ImportIndexHasher::CURRENT_VERSION])
            || $this->importOptions->resolve($definition, $storedImporterOptions) !== $storedImporterOptions) {
            throw new InvalidArgumentException('The saved staging options are not a valid snapshot for this importer.');
        }

        $dataStartRow = $session->sheet_header_row === null ? 1 : $session->sheet_header_row + 1;
        $sourceBatch = [];
        $batchSize = max(1, (int) config('smart-import.reading.chunk_size', 250));

        foreach ($this->reader->readRows(
            $session,
            $session->sheet_index,
            $dataStartRow,
            $readerOptions['csv_delimiter'],
            $readerOptions['csv_encoding'],
        ) as $sourceRow) {
            $sourceBatch[] = $sourceRow;

            if (count($sourceBatch) >= $batchSize) {
                $batch = $this->stagedAttributesForBatch($definition, $sourceBatch, $session->mapping, $session->sheet_name, $stagingRevision, $storedSecurityOptions['index_hash_version']);

                if (! $this->persistBatch($sessionId, $mappingRevision, $stagingRevision, $batch)) {
                    return;
                }

                $sourceBatch = [];
            }
        }

        if ($sourceBatch !== []) {
            $batch = $this->stagedAttributesForBatch($definition, $sourceBatch, $session->mapping, $session->sheet_name, $stagingRevision, $storedSecurityOptions['index_hash_version']);

            if (! $this->persistBatch($sessionId, $mappingRevision, $stagingRevision, $batch)) {
                return;
            }
        }

        $this->finish($sessionId, $mappingRevision, $stagingRevision);
    }

    /** Mark a staging revision failed without exposing parser or worker exception details. */
    public function fail(string $sessionId, int $mappingRevision, int $stagingRevision): void
    {
        DB::transaction(function () use ($sessionId, $mappingRevision, $stagingRevision): void {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if (! $this->matchesRevision($session, $mappingRevision, $stagingRevision)) {
                return;
            }

            $session->transitionTo(ImportStatus::Failed);
            $session->forceFill([
                'error_summary' => 'Staging failed. Check the source file and importer configuration before retrying.',
                'heartbeat_at' => now(),
                'finished_at' => now(),
            ]);
            $session->save();
            $this->outbox->recordTerminal($session, deferred: true);
            $this->audit->record($session, 'staging.failed', reasonCode: 'worker_failure', metadata: [
                'staging_revision' => $session->staging_revision,
                'recovery_count' => $session->recovery_count,
            ]);
            $this->audit->record($session, 'import.failed', reasonCode: 'staging_failure', metadata: [
                'status' => ImportStatus::Failed->value,
                'attempt_id' => max(0, (int) $session->current_attempt),
                'processed' => (int) $session->processed,
                'created' => (int) $session->created_count,
                'updated' => (int) $session->updated_count,
                'skipped' => (int) $session->skipped_count,
                'failed' => (int) $session->failed_count,
            ]);
        });
    }

    /** @param list<array<string, mixed>> $batch */
    private function persistBatch(string $sessionId, int $mappingRevision, int $stagingRevision, array $batch): bool
    {
        return DB::transaction(function () use ($sessionId, $mappingRevision, $stagingRevision, $batch): bool {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if (! $this->matchesRevision($session, $mappingRevision, $stagingRevision)) {
                return false;
            }

            $rowNumbers = array_column($batch, 'source_row_number');
            $existingRows = ImportRow::query()
                ->where('import_id', $sessionId)
                ->whereIn('source_row_number', $rowNumbers)
                ->get()
                ->keyBy('source_row_number');
            $newCount = 0;
            $validCount = 0;
            $invalidCount = 0;
            $rowsToInsert = [];
            $timestamp = now();

            foreach ($batch as $attributes) {
                $sourceRowNumber = $attributes['source_row_number'];
                $existing = $existingRows->get($sourceRowNumber);

                if ($existing !== null && $existing->staging_revision === $stagingRevision) {
                    if (! hash_equals((string) $existing->source_fingerprint, $attributes['source_fingerprint'])) {
                        throw new InvalidArgumentException('A source row changed within the frozen staging revision.');
                    }

                    continue;
                }

                if ($existing !== null) {
                    $existing->forceFill(array_merge($attributes, ['import_id' => $sessionId]));
                    $existing->save();
                } else {
                    $row = new ImportRow();
                    $row->forceFill(array_merge($attributes, ['import_id' => $sessionId]));

                    if ($row->usesTimestamps()) {
                        $row->setCreatedAt($timestamp);
                        $row->setUpdatedAt($timestamp);
                    }

                    $rowsToInsert[] = $row->getAttributes();
                }

                $newCount++;
                $validCount += $attributes['validation_status'] === 'valid' ? 1 : 0;
                $invalidCount += $attributes['validation_status'] === 'invalid' ? 1 : 0;
            }

            // Fifty rows stay under conservative SQLite bind-variable limits and preserve the batch transaction.
            foreach (array_chunk($rowsToInsert, 50) as $insertChunk) {
                ImportRow::query()->insert($insertChunk);
            }

            if ($newCount > 0) {
                $session->forceFill([
                    'stage_processed' => $session->stage_processed + $newCount,
                    'stage_valid' => $session->stage_valid + $validCount,
                    'stage_invalid' => $session->stage_invalid + $invalidCount,
                    'heartbeat_at' => now(),
                ]);
                $session->save();
            }

            return true;
        });
    }

    /**
     * @param list<array{row_number: int, values: array<int, string|int|float|bool|null>}> $sourceRows
     * @param array<int, string|null> $mapping
     * @return list<array<string, mixed>>
     */
    private function stagedAttributesForBatch(
        ImportDefinition $definition,
        array $sourceRows,
        array $mapping,
        string $sourceSheet,
        int $stagingRevision,
        int $indexHashVersion,
    ): array {
        $processedRows = $this->rowDataProcessor->processBatch($definition, $sourceRows, $mapping);
        $targetMatches = $this->targetRecordMatcher->matchBatch(
            $definition,
            array_map(static fn (array $result): array => $result['normalized_values'], $processedRows),
        );
        $attributes = [];
        $sensitiveFields = [];

        foreach ($definition->fields() as $field) {
            if ($field instanceof \SmartImport\Data\ImportField && $field->sensitive) {
                $sensitiveFields[$field->name] = true;
            }
        }

        $sensitiveRawColumns = [];

        foreach ($mapping as $sourceColumn => $fieldName) {
            if ($fieldName !== null && isset($sensitiveFields[$fieldName])) {
                $sensitiveRawColumns[] = $sourceColumn;
            }
        }

        foreach ($sourceRows as $index => $sourceRow) {
            $result = $processedRows[$index];
            $targetMatch = $targetMatches[$index];
            $errors = $result['errors'];

            foreach ($targetMatch['errors'] as $error) {
                $errors[] = $error + ['source_row' => $sourceRow['row_number']];
            }

            $fingerprint = $this->indexHasher->hash(json_encode(
                [$sourceSheet, $sourceRow['row_number'], $sourceRow['values']],
                JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ), $indexHashVersion);

            $attributes[] = [
                'source_sheet' => $sourceSheet,
                'source_row_number' => $sourceRow['row_number'],
                'staging_revision' => $stagingRevision,
                'source_fingerprint' => $fingerprint,
                'match_key_hash' => $this->stagedRows->matchKeyHash($definition, $result['normalized_values'], $indexHashVersion),
                'resolved_target_type' => $targetMatch['target_type'],
                'resolved_target_id' => $targetMatch['target_id'],
                'change_type' => $targetMatch['change_type'],
                'raw_values' => $this->payloadCodec->protect($sourceRow['values'], $sensitiveRawColumns),
                'mapped_values' => $this->payloadCodec->protect($result['mapped_values'], array_keys($sensitiveFields)),
                'normalized_values' => $this->payloadCodec->protect($result['normalized_values'], array_keys($sensitiveFields)),
                'validation_errors' => $errors,
                'warnings' => [],
                'validation_status' => $errors === [] ? 'valid' : 'invalid',
                'execution_status' => 'pending',
                'preview' => null,
            ];
        }

        return $attributes;
    }

    private function finish(string $sessionId, int $mappingRevision, int $stagingRevision): void
    {
        if (! $this->stagedRows->recheckFile($sessionId, $mappingRevision, $stagingRevision)) {
            return;
        }

        DB::transaction(function () use ($sessionId, $mappingRevision, $stagingRevision): void {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if (! $this->matchesRevision($session, $mappingRevision, $stagingRevision)) {
                return;
            }

            $session->forceFill([
                'stage_total' => $session->stage_processed,
                'heartbeat_at' => now(),
            ]);
            $session->transitionTo(ImportStatus::ReviewReady);
            $session->save();
            $this->audit->record($session, 'staging.completed', reasonCode: 'staging_finished', metadata: [
                'staging_revision' => $session->staging_revision,
                'stage_total' => $session->stage_total,
                'valid' => $session->stage_valid,
                'invalid' => $session->stage_invalid,
                'warnings' => $session->stage_warning,
            ]);
        });
    }

    private function currentSession(string $sessionId, int $mappingRevision, int $stagingRevision): ?ImportSession
    {
        $session = ImportSession::query()->find($sessionId);

        return $this->matchesRevision($session, $mappingRevision, $stagingRevision) ? $session : null;
    }

    private function matchesRevision(?ImportSession $session, int $mappingRevision, int $stagingRevision): bool
    {
        return $session !== null
            && $session->status === ImportStatus::Staging
            && $session->mapping_revision === $mappingRevision
            && $session->staging_revision === $stagingRevision;
    }
}
