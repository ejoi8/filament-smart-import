<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SmartImport\Contracts\ImportDefinition;
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
    ): ImportSession {
        $session = $this->coordinator->get($sessionId, $actor);
        $definition = $this->importers->get($importerKey);
        $this->authorization->authorize('useImporter', $actor, $definition);
        $sheets = $this->reader->listSheets($session);

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

            $preview = $this->reader->preview($session, $sheetIndex, $headerRow, 1, $headerRow);
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

        $dataStartRow = $session->sheet_header_row === null ? 1 : $session->sheet_header_row + 1;
        $sourceBatch = [];
        $batchSize = max(1, (int) config('smart-import.reading.chunk_size', 250));

        foreach ($this->reader->readRows($session, $session->sheet_index, $dataStartRow) as $sourceRow) {
            $sourceBatch[] = $sourceRow;

            if (count($sourceBatch) >= $batchSize) {
                $batch = $this->stagedAttributesForBatch($definition, $sourceBatch, $session->mapping, $session->sheet_name, $stagingRevision);

                if (! $this->persistBatch($sessionId, $mappingRevision, $stagingRevision, $batch)) {
                    return;
                }

                $sourceBatch = [];
            }
        }

        if ($sourceBatch !== []) {
            $batch = $this->stagedAttributesForBatch($definition, $sourceBatch, $session->mapping, $session->sheet_name, $stagingRevision);

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

            foreach ($batch as $attributes) {
                $sourceRowNumber = $attributes['source_row_number'];
                $existing = $existingRows->get($sourceRowNumber);

                if ($existing !== null && $existing->staging_revision === $stagingRevision) {
                    if (! hash_equals((string) $existing->source_fingerprint, $attributes['source_fingerprint'])) {
                        throw new InvalidArgumentException('A source row changed within the frozen staging revision.');
                    }

                    continue;
                }

                $row = $existing ?? new ImportRow();
                $row->forceFill(array_merge($attributes, ['import_id' => $sessionId]));
                $row->save();
                $newCount++;
                $validCount += $attributes['validation_status'] === 'valid' ? 1 : 0;
                $invalidCount += $attributes['validation_status'] === 'invalid' ? 1 : 0;
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
    ): array {
        $processedRows = $this->rowDataProcessor->processBatch($definition, $sourceRows, $mapping);
        $targetMatches = $this->targetRecordMatcher->matchBatch(
            $definition,
            array_map(static fn (array $result): array => $result['normalized_values'], $processedRows),
        );
        $attributes = [];

        foreach ($sourceRows as $index => $sourceRow) {
            $result = $processedRows[$index];
            $targetMatch = $targetMatches[$index];
            $errors = $result['errors'];

            foreach ($targetMatch['errors'] as $error) {
                $errors[] = $error + ['source_row' => $sourceRow['row_number']];
            }

            $fingerprint = hash('sha256', json_encode(
                [$sourceSheet, $sourceRow['row_number'], $sourceRow['values']],
                JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ));

            $attributes[] = [
                'source_sheet' => $sourceSheet,
                'source_row_number' => $sourceRow['row_number'],
                'staging_revision' => $stagingRevision,
                'source_fingerprint' => $fingerprint,
                'match_key_hash' => $this->stagedRows->matchKeyHash($definition, $result['normalized_values']),
                'resolved_target_type' => $targetMatch['target_type'],
                'resolved_target_id' => $targetMatch['target_id'],
                'change_type' => $targetMatch['change_type'],
                'raw_values' => $sourceRow['values'],
                'mapped_values' => $result['mapped_values'],
                'normalized_values' => $result['normalized_values'],
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
