<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportExecutionContext;
use SmartImport\Data\ImportField;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use Throwable;

final class StagedRowService
{
    private const ROW_BATCH_SIZE = 250;

    private const VALIDATION_STATUSES = ['valid', 'invalid', 'warning'];

    public function __construct(
        private ImportCoordinator $coordinator,
        private ImportAuthorization $authorization,
        private ImporterRegistry $importers,
        private RowDataProcessor $rowDataProcessor,
        private TargetRecordMatcher $targetRecordMatcher,
        private ImportExecutionContext $executionContext,
        private ImportPayloadCodec $payloadCodec,
        private ImportAuditService $audit,
    ) {}

    /**
     * Return authorized staged rows, optionally filtered by validation status.
     *
     * @return Collection<int, ImportRow>
     */
    public function rows(string $sessionId, Authenticatable $actor, ?string $validationStatus = null): Collection
    {
        if ($validationStatus !== null && ! in_array($validationStatus, self::VALIDATION_STATUSES, true)) {
            throw new InvalidArgumentException('The requested row validation status is not supported.');
        }

        $session = $this->coordinator->get($sessionId, $actor);
        $query = $session->rows()->orderBy('source_row_number')->orderBy('id');

        if ($validationStatus !== null) {
            $query->where('validation_status', $validationStatus);
        }

        return $query->get();
    }

    /** Edit one mapped cell, re-run its field transforms/rules, and refresh affected file duplicate keys. */
    public function edit(
        string $sessionId,
        int $rowId,
        string $fieldName,
        mixed $value,
        Authenticatable $actor,
        int $expectedStagingRevision,
    ): ImportRow
    {
        $this->assertEditableValue($value);

        DB::transaction(function () use ($sessionId, $rowId, $fieldName, $value, $actor, $expectedStagingRevision): void {
            $session = $this->authorization
                ->applyActorScope(ImportSession::query()->lockForUpdate(), $actor)
                ->whereKey($sessionId)
                ->firstOrFail();

            $this->authorization->authorize('view', $actor, $session);
            $this->authorization->authorize('update', $actor, $session);

            if ($session->status !== ImportStatus::ReviewReady) {
                throw new \DomainException('Staged rows can be edited only while the import is ready for review.');
            }

            if ($session->staging_revision !== $expectedStagingRevision) {
                throw new \DomainException('This import changed after you opened the review. Refresh before editing.');
            }

            if ($session->importer_key === null || $session->importer_version === null || $session->mapping === null) {
                throw new \DomainException('The staged import configuration is incomplete.');
            }

            $definition = $this->importers->get($session->importer_key);
            $this->authorization->authorize('useImporter', $actor, $definition);

            if ($definition->version() !== $session->importer_version) {
                throw new \DomainException('The importer definition changed after these rows were staged.');
            }

            $field = $this->field($definition, $fieldName);

            if ($field->sensitive) {
                $this->authorization->authorize('editSensitiveImportFields', $actor, [$session, $field]);
            }

            $mapping = $session->mapping;

            if (! in_array($fieldName, $mapping, true)) {
                throw new InvalidArgumentException(sprintf('Field [%s] is not mapped in this staged import.', $fieldName));
            }

            $row = ImportRow::query()
                ->where('import_id', $sessionId)
                ->whereKey($rowId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($row->execution_status !== 'pending') {
                throw new \DomainException('A row that has entered execution cannot be edited.');
            }

            $previousStatus = $row->validation_status;
            $oldMatchKeyHash = $row->match_key_hash;
            $mappedValues = $this->payloadCodec->reveal($row->mapped_values);
            $mappedValues[$field->name] = $value;
            $sourceValues = [];

            foreach ($mapping as $sourceColumn => $mappedField) {
                if ($mappedField !== null && array_key_exists($mappedField, $mappedValues)) {
                    $sourceValues[$sourceColumn] = $mappedValues[$mappedField];
                }
            }

            [$result, $targetMatch] = $this->executionContext->run($session, function () use ($definition, $sourceValues, $mapping, $row): array {
                $result = $this->rowDataProcessor->process(
                    $definition,
                    $sourceValues,
                    $mapping,
                    $row->source_row_number,
                );

                return [$result, $this->targetRecordMatcher->matchBatch($definition, [$result['normalized_values']])[0]];
            });
            $errors = $result['errors'];

            foreach ($targetMatch['errors'] as $error) {
                $errors[] = $error + ['source_row' => $row->source_row_number];
            }

            $indexHashVersion = (int) ($session->options['security']['index_hash_version'] ?? 1);
            $newMatchKeyHash = $this->matchKeyHash($definition, $result['normalized_values'], $indexHashVersion);
            $nextRevision = $session->staging_revision + 1;
            $newStatus = $errors === [] ? 'valid' : 'invalid';

            $row->forceFill([
                'mapped_values' => $this->payloadCodec->protect($result['mapped_values'], $this->sensitiveFieldNames($definition)),
                'normalized_values' => $this->payloadCodec->protect($result['normalized_values'], $this->sensitiveFieldNames($definition)),
                'validation_errors' => $errors,
                'validation_status' => $newStatus,
                'match_key_hash' => $newMatchKeyHash,
                'resolved_target_type' => $targetMatch['target_type'],
                'resolved_target_id' => $targetMatch['target_id'],
                'change_type' => $targetMatch['change_type'],
                'staging_revision' => $nextRevision,
            ]);
            $row->save();

            ImportRow::query()
                ->where('import_id', $sessionId)
                ->update(['preview' => null]);

            $session->forceFill(['staging_revision' => $nextRevision]);
            $options = $session->options ?? [];
            unset(
                $options['preview_revision'],
                $options['preview_mapping_revision'],
                $options['preview_generation_id'],
                $options['preview_recovery_count'],
                $options['preview_processed'],
                $options['preview_total'],
                $options['preview_summary'],
                $options['preview_error'],
                $options['preview_heartbeat_at'],
                $options['preview_state'],
            );
            $session->options = $options;
            $this->adjustValidationCounters($session, $previousStatus, $newStatus);
            $session->save();
            $this->audit->record($session, 'row.corrected', $actor, 'operator_correction', [
                'row_number' => $row->source_row_number,
                'staging_revision' => $session->staging_revision,
                'field_count' => 1,
            ]);

            $matchHashes = array_values(array_unique(array_filter([$oldMatchKeyHash, $newMatchKeyHash], 'is_string')));

            if ($matchHashes !== []) {
                $this->recheckHashes($sessionId, $session->mapping_revision, $nextRevision, $matchHashes, ImportStatus::ReviewReady);
            }
        });

        return ImportRow::query()
            ->where('import_id', $sessionId)
            ->whereKey($rowId)
            ->firstOrFail();
    }

    /** Return a stable hash for the definition's complete, non-blank composite key. */
    public function matchKeyHash(ImportDefinition $definition, array $normalizedValues, int $version = ImportIndexHasher::CURRENT_VERSION): ?string
    {
        return $this->targetRecordMatcher->keyHash($definition, $normalizedValues, $version);
    }

    /** Recheck all duplicate key groups for one current staging revision before review is unlocked. */
    public function recheckFile(string $sessionId, int $mappingRevision, int $stagingRevision): bool
    {
        $session = ImportSession::query()->find($sessionId);

        if (! $this->isCurrent($session, $mappingRevision, $stagingRevision, ImportStatus::Staging)) {
            return false;
        }

        if ($session->importer_key === null) {
            return false;
        }

        $definition = $this->importers->get($session->importer_key);

        if ($definition->uniqueKeyFields() === []) {
            return true;
        }

        $duplicates = ImportRow::query()
            ->select('match_key_hash')
            ->where('import_id', $sessionId)
            ->where('staging_revision', $stagingRevision)
            ->whereNotNull('match_key_hash')
            ->groupBy('match_key_hash')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('match_key_hash');
        $current = true;

        $duplicates->chunk(self::ROW_BATCH_SIZE, function (Collection $groups) use ($sessionId, $mappingRevision, $stagingRevision, &$current): bool {
            $hashes = $groups->pluck('match_key_hash')->all();

            $current = $this->recheckHashes($sessionId, $mappingRevision, $stagingRevision, $hashes, ImportStatus::Staging);

            return $current;
        });

        return $current;
    }

    /** @param list<string> $hashes */
    private function recheckHashes(
        string $sessionId,
        int $mappingRevision,
        int $stagingRevision,
        array $hashes,
        ImportStatus $requiredStatus,
    ): bool {
        $hashes = array_values(array_unique(array_filter($hashes, 'is_string')));

        if ($hashes === []) {
            return true;
        }

        $lastId = 0;

        while (true) {
            $hasMoreRows = DB::transaction(function () use (
                $sessionId,
                $mappingRevision,
                $stagingRevision,
                $hashes,
                $requiredStatus,
                &$lastId,
            ): ?bool {
                $session = ImportSession::query()->lockForUpdate()->find($sessionId);

                if (! $this->isCurrent($session, $mappingRevision, $stagingRevision, $requiredStatus)) {
                    return null;
                }

                $rows = ImportRow::query()
                    ->where('import_id', $sessionId)
                    ->whereIn('match_key_hash', $hashes)
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit(self::ROW_BATCH_SIZE)
                    ->lockForUpdate()
                    ->get();

                if ($rows->isEmpty()) {
                    return false;
                }

                $chunkHashes = $rows->pluck('match_key_hash')->unique()->values()->all();
                $duplicateHashes = ImportRow::query()
                    ->select('match_key_hash')
                    ->where('import_id', $sessionId)
                    ->whereIn('match_key_hash', $chunkHashes)
                    ->groupBy('match_key_hash')
                    ->havingRaw('COUNT(*) > 1')
                    ->pluck('match_key_hash')
                    ->all();
                $duplicateLookup = array_fill_keys($duplicateHashes, true);
                $definition = $this->importers->get((string) $session->importer_key);
                $keyFields = $definition->uniqueKeyFields();
                $sessionChanged = false;

                foreach ($rows as $row) {
                    $oldStatus = $row->validation_status;
                    $oldErrors = $row->validation_errors ?? [];
                    $errors = array_values(array_filter(
                        $oldErrors,
                        static fn (mixed $error): bool => ! is_array($error) || ($error['code'] ?? null) !== 'duplicate_in_file',
                    ));
                    $isDuplicate = is_string($row->match_key_hash) && isset($duplicateLookup[$row->match_key_hash]);

                    if ($isDuplicate) {
                        foreach ($keyFields as $fieldName) {
                            $errors[] = [
                                'field' => $fieldName,
                                'code' => 'duplicate_in_file',
                                'safe_message' => 'This value is part of a duplicate key in another file row.',
                                'source_row' => $row->source_row_number,
                            ];
                        }
                    }

                    $newStatus = $errors === [] ? 'valid' : 'invalid';

                    if ($oldErrors !== $errors || $oldStatus !== $newStatus) {
                        $row->forceFill([
                            'validation_errors' => $errors,
                            'validation_status' => $newStatus,
                            'staging_revision' => $stagingRevision,
                        ]);
                        $row->save();
                        $this->adjustValidationCounters($session, $oldStatus, $newStatus);
                        $sessionChanged = true;
                    }
                }

                $lastId = (int) $rows->last()->getKey();

                if ($sessionChanged) {
                    $session->save();
                }

                return true;
            });

            if ($hasMoreRows === null) {
                return false;
            }

            if ($hasMoreRows === false) {
                return true;
            }
        }
    }

    private function adjustValidationCounters(ImportSession $session, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $counterByStatus = [
            'valid' => 'stage_valid',
            'invalid' => 'stage_invalid',
            'warning' => 'stage_warning',
        ];

        if (isset($counterByStatus[$from])) {
            $counter = $counterByStatus[$from];
            $session->{$counter} = max(0, $session->{$counter} - 1);
        }

        if (isset($counterByStatus[$to])) {
            $counter = $counterByStatus[$to];
            $session->{$counter}++;
        }
    }

    private function assertEditableValue(mixed $value): void
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null) {
            throw new InvalidArgumentException('Edited cell values must be scalar or null.');
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Edited cell values must be finite numbers.');
        }

        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Edited cell values must be valid UTF-8.');
            }

            $maximumLength = (int) config('smart-import.reading.max_cell_length', 32767);

            if (mb_strlen($value, 'UTF-8') > $maximumLength) {
                throw new InvalidArgumentException('The edited cell exceeds the configured text length limit.');
            }
        }
    }

    private function field(ImportDefinition $definition, string $name): ImportField
    {
        foreach ($definition->fields() as $field) {
            if ($field instanceof ImportField && $field->name === $name) {
                return $field;
            }
        }

        throw new InvalidArgumentException(sprintf('Field [%s] is not defined by the selected importer.', $name));
    }

    /** @return list<string> */
    private function sensitiveFieldNames(ImportDefinition $definition): array
    {
        $names = [];

        foreach ($definition->fields() as $field) {
            if ($field instanceof ImportField && $field->sensitive) {
                $names[] = $field->name;
            }
        }

        return $names;
    }

    private function isCurrent(?ImportSession $session, int $mappingRevision, int $stagingRevision, ImportStatus $status): bool
    {
        return $session !== null
            && $session->status === $status
            && $session->mapping_revision === $mappingRevision
            && $session->staging_revision === $stagingRevision;
    }
}
