<?php

namespace SmartImport\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SmartImport\Exceptions\ImportCancellationRequested;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportExecutionContext;
use SmartImport\Data\ImportRowContext;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\ImportWriteAction;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;

final class ImportExecutionService
{
    public function __construct(
        private ImporterRegistry $importers,
        private ImportHookDispatcher $hooks,
        private RowDataProcessor $rowDataProcessor,
        private StagedRowService $stagedRows,
        private TargetRecordMatcher $targetRecordMatcher,
        private ImportValueComparator $valueComparator,
        private ImportExecutionContext $executionContext,
        private ImportPayloadCodec $payloadCodec,
        private ImportCoordinator $coordinator,
    ) {}

    /**
     * Process one confirmed staged row. Repeated delivery returns its persisted outcome without
     * writing the target again or incrementing session counters a second time.
     *
     * @return array{execution_status: string, target_type: string|null, target_id: string|null, already_processed: bool}
     */
    public function processRow(string $sessionId, int $rowId, int $confirmedRevision, ?int $attemptId = null): array
    {
        $session = ImportSession::query()->findOrFail($sessionId);
        return $this->executionContext->run(
            $session,
            fn (): array => $this->processRowWithinContext($sessionId, $rowId, $confirmedRevision, $attemptId),
        );
    }

    private function processRowWithinContext(string $sessionId, int $rowId, int $confirmedRevision, ?int $attemptId): array
    {
        $session = ImportSession::query()->findOrFail($sessionId);
        $this->coordinator->assertImportableIntake($session);
        $definition = $this->definition($session);
        $target = $this->targetModel($definition);
        $connectionName = $session->getConnection()->getName();

        if ($target->getConnection()->getName() !== $connectionName
            || (new ImportRow())->getConnection()->getName() !== $connectionName) {
            throw new DomainException('Import session, staged rows, and target model must use the same database connection for atomic row execution.');
        }

        return $session->getConnection()->transaction(function () use (
            $sessionId,
            $rowId,
            $confirmedRevision,
            $attemptId,
            $connectionName,
        ): array {
            $session = ImportSession::query()->findOrFail($sessionId);
            $row = ImportRow::query()
                ->where('import_id', $sessionId)
                ->whereKey($rowId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($row->execution_status, ['created', 'updated', 'skipped', 'failed'], true)) {
                return $this->result($row, true);
            }

            if ($session->status === ImportStatus::Cancelling) {
                throw new ImportCancellationRequested();
            }

            if (! in_array($session->status, [ImportStatus::Queued, ImportStatus::Importing], true)
                || $confirmedRevision < 1
                || $session->confirmed_revision !== $confirmedRevision
                || $session->staging_revision !== $confirmedRevision
                || ($attemptId !== null && $session->current_attempt !== $attemptId)) {
                throw new DomainException('The import session is not executing the confirmed staging revision.');
            }

            if ($row->execution_status !== 'pending') {
                throw new DomainException('The staged row has an unsupported execution state.');
            }

            $definition = $this->definition($session);
            $preview = $row->preview;

            if (! is_array($preview)
                || ($preview['staging_revision'] ?? null) !== $session->confirmed_revision
                || ($preview['mapping_revision'] ?? null) !== $session->mapping_revision
                || ImportWriteAction::tryFrom((string) ($preview['action'] ?? '')) === null) {
                $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, 'stale_preview');

                return $this->result($row->fresh(), false);
            }

            if (! in_array($row->validation_status, ['valid', 'warning'], true)) {
                $firstError = ($row->validation_errors ?? [])[0] ?? null;
                $code = is_array($firstError) && is_string($firstError['code'] ?? null)
                    ? $firstError['code']
                    : 'validation_failed';

                $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, $code);

                return $this->result($row->fresh(), false);
            }

            $processed = $this->revalidate($definition, $session, $row);

            if ($processed['errors'] !== []) {
                $firstError = $processed['errors'][0];
                $this->finishRow(
                    $row,
                    $session,
                    $connectionName,
                    ImportWriteAction::Fail,
                    is_string($firstError['code'] ?? null) ? $firstError['code'] : 'validation_failed',
                );

                return $this->result($row->fresh(), false);
            }

            $mappedValues = $this->payloadCodec->reveal($row->mapped_values);
            $normalizedValues = $this->payloadCodec->reveal($row->normalized_values);

            if ($processed['mapped_values'] !== $mappedValues
                || $processed['normalized_values'] !== $normalizedValues
                || $this->stagedRows->matchKeyHash(
                    $definition,
                    $processed['normalized_values'],
                    (int) ($session->options['security']['index_hash_version'] ?? 1),
                ) !== $row->match_key_hash) {
                $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, 'staged_values_changed');

                return $this->result($row->fresh(), false);
            }

            if ($this->isDuplicateInFile($sessionId, $row)) {
                $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, 'duplicate_in_file');

                return $this->result($row->fresh(), false);
            }

            $matches = $this->targetRecordMatcher->matchBatch($definition, [$processed['normalized_values']], true)[0];

            if ($matches['errors'] !== []) {
                $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, 'target_match_ambiguous');

                return $this->result($row->fresh(), false);
            }

            $decision = $definition->writePolicy()->decisionFor($matches['change_type']);
            $unchangedPreview = $preview['action'] === ImportWriteAction::Skip->value
                && ($preview['reason'] ?? null) === 'unchanged';

            if ($decision['action'] === ImportWriteAction::Update
                && ($preview['action'] === ImportWriteAction::Update->value || $unchangedPreview)) {
                if ((string) ($matches['target_id'] ?? '') !== (string) ($preview['target_id'] ?? '')) {
                    $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, 'target_changed_after_preview');

                    return $this->result($row->fresh(), false);
                }

                $currentTarget = $this->lockTarget($definition, (string) $matches['target_id']);
                $snapshot = $preview['target_snapshot'] ?? [];

                if (! $currentTarget instanceof Model
                    || ! $this->targetRecordMatcher->matchesKey($definition, $processed['normalized_values'], $currentTarget)
                    || ! $this->valueComparator->matchesTargetSnapshot(
                        $currentTarget,
                        is_array($snapshot) ? $snapshot : [],
                        array_keys($processed['normalized_values']),
                    )) {
                    $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, 'target_changed_after_preview');

                    return $this->result($row->fresh(), false);
                }

                if ($unchangedPreview) {
                    if ($this->valueComparator->targetHasChanges($currentTarget, $processed['normalized_values'], $definition)) {
                        $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, 'target_changed_after_preview');

                        return $this->result($row->fresh(), false);
                    }

                    $this->finishRow(
                        $row,
                        $session,
                        $connectionName,
                        ImportWriteAction::Skip,
                        'unchanged',
                        $matches['target_type'],
                        $matches['target_id'],
                    );

                    return $this->result($row->fresh(), false);
                }
            }

            if ($decision['action']->value !== $preview['action']
                || ($decision['action'] === ImportWriteAction::Update
                    && (string) ($matches['target_id'] ?? '') !== (string) ($preview['target_id'] ?? ''))) {
                $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, 'target_changed_after_preview');

                return $this->result($row->fresh(), false);
            }

            if ($decision['action'] === ImportWriteAction::Fail) {
                $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, $decision['reason']);

                return $this->result($row->fresh(), false);
            }

            if ($decision['action'] === ImportWriteAction::Skip) {
                $this->finishRow($row, $session, $connectionName, ImportWriteAction::Skip, $decision['reason'], $matches['target_type'], $matches['target_id']);

                return $this->result($row->fresh(), false);
            }

            $beforeContext = $this->hookContext(
                $session,
                $row,
                $decision['action'],
                $definition->targetModel(),
                $matches['target_id'],
                $processed['normalized_values'],
            );
            $this->hooks->beforeRowPersist($definition, $beforeContext);

            try {
                $written = $this->writeTarget(
                    $definition,
                    $decision['action'],
                    $matches['target_id'],
                    $processed['normalized_values'],
                );
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                $race = $this->targetRecordMatcher->matchBatch($definition, [$processed['normalized_values']], true)[0];
                $raceDecision = $definition->writePolicy()->decisionFor($race['change_type']);

                if ($race['errors'] !== []
                    || $race['change_type'] !== 'matched'
                    || ! in_array($raceDecision['action'], [ImportWriteAction::Skip, ImportWriteAction::Fail], true)) {
                    $this->finishRow($row, $session, $connectionName, ImportWriteAction::Fail, 'target_unique_conflict');

                    return $this->result($row->fresh(), false);
                }

                $this->finishRow(
                    $row,
                    $session,
                    $connectionName,
                    $raceDecision['action'],
                    $raceDecision['reason'],
                    $race['target_type'],
                    $race['target_id'],
                );

                return $this->result($row->fresh(), false);
            }

            $outcome = $decision['action'];
            $targetId = (string) $written->getKey();
            $this->finishRow($row, $session, $connectionName, $outcome, null, $definition->targetModel(), $targetId);
            $this->hooks->afterRowPersist(
                $definition,
                $this->hookContext($session, $row, $outcome, $definition->targetModel(), $targetId, $processed['normalized_values']),
                DB::connection($connectionName),
            );

            return $this->result($row->fresh(), false);
        }, 3);
    }

    private function definition(ImportSession $session): ImportDefinition
    {
        if ($session->importer_key === null || $session->importer_version === null) {
            throw new DomainException('The staged import configuration is incomplete.');
        }

        $definition = $this->importers->get($session->importer_key);

        if ($definition->version() !== $session->importer_version
            || $definition->targetModel() !== $session->target_model) {
            throw new DomainException('The importer definition changed after confirmation.');
        }

        return $definition;
    }

    private function targetModel(ImportDefinition $definition): Model
    {
        $model = $definition->targetModel();

        if (! is_a($model, Model::class, true)) {
            throw new DomainException('The configured importer target must be an Eloquent model.');
        }

        return new $model();
    }

    private function lockTarget(ImportDefinition $definition, string $targetId): ?Model
    {
        $modelClass = $definition->targetModel();
        $model = new $modelClass();

        return $model->newQuery()->whereKey($targetId)->lockForUpdate()->first();
    }

    /** @return array{mapped_values: array<string, mixed>, normalized_values: array<string, mixed>, errors: array<int, array<string, mixed>>, valid: bool} */
    private function revalidate(ImportDefinition $definition, ImportSession $session, ImportRow $row): array
    {
        $mapping = $session->mapping;

        if (! is_array($mapping)) {
            throw new DomainException('The confirmed import mapping is unavailable.');
        }

        $sourceValues = [];

        $mappedValues = $this->payloadCodec->reveal($row->mapped_values);

        foreach ($mapping as $sourceColumn => $fieldName) {
            if ($fieldName !== null && array_key_exists($fieldName, $mappedValues)) {
                $sourceValues[$sourceColumn] = $mappedValues[$fieldName];
            }
        }

        return $this->rowDataProcessor->process($definition, $sourceValues, $mapping, $row->source_row_number);
    }

    private function isDuplicateInFile(string $sessionId, ImportRow $row): bool
    {
        return $row->match_key_hash !== null && ImportRow::query()
            ->where('import_id', $sessionId)
            ->where('match_key_hash', $row->match_key_hash)
            ->where('id', '<>', $row->getKey())
            ->exists();
    }

    /** @param array<string, mixed> $values */
    private function hookContext(
        ImportSession $session,
        ImportRow $row,
        ImportWriteAction $action,
        string $targetType,
        ?string $targetId,
        array $values,
    ): ImportRowContext {
        return new ImportRowContext(
            (string) $session->getKey(),
            (string) $session->importer_key,
            (string) $session->importer_version,
            (int) $session->confirmed_revision,
            (int) $row->getKey(),
            (int) $row->source_row_number,
            $action,
            $targetType,
            $targetId,
            $values,
            (int) $session->current_attempt,
            is_array($session->options['importer'] ?? null) ? $session->options['importer'] : [],
        );
    }

    /** @param array<string, mixed> $values */
    private function writeTarget(ImportDefinition $definition, ImportWriteAction $action, ?string $targetId, array $values): Model
    {
        $model = $this->targetModel($definition);

        if ($action === ImportWriteAction::Update) {
            if ($targetId === null) {
                throw new DomainException('An update action is missing its matched target identifier.');
            }

            $model = $model->newQuery()->whereKey($targetId)->lockForUpdate()->first();

            if (! $model instanceof Model || ! $this->targetRecordMatcher->matchesKey($definition, $values, $model)) {
                throw new DomainException('The matched target changed before its row could be written.');
            }
        }

        $allowedFields = [];

        foreach ($definition->fields() as $field) {
            $allowedFields[$field->name] = true;
        }

        foreach ($values as $fieldName => $value) {
            if (! is_string($fieldName) || ! isset($allowedFields[$fieldName])) {
                throw new DomainException('The staged row contains a field outside its importer allowlist.');
            }

            $model->setAttribute($fieldName, $value);
        }

        $connection = $model->getConnection();

        return $connection->transaction(function () use ($model): Model {
            $model->save();

            return $model;
        }, 3);
    }

    private function finishRow(
        ImportRow $row,
        ImportSession $session,
        string $connectionName,
        ImportWriteAction $outcome,
        ?string $errorCode,
        ?string $targetType = null,
        ?string $targetId = null,
    ): void {
        $now = now();
        $status = match ($outcome) {
            ImportWriteAction::Create => 'created',
            ImportWriteAction::Update => 'updated',
            ImportWriteAction::Skip => 'skipped',
            ImportWriteAction::Fail => 'failed',
        };
        $history = $row->execution_history ?? [];
        $history[] = [
            'attempt' => $row->attempt_count + 1,
            'status' => $status,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'error_code' => $errorCode,
            'completed_at' => $now->toISOString(),
        ];
        $row->forceFill([
            'execution_status' => $status,
            'last_error_code' => $errorCode,
            'last_error_message' => $errorCode === null ? null : $this->safeMessage($errorCode),
            'attempt_count' => $row->attempt_count + 1,
            'execution_history' => $history,
            'completed_at' => $now,
        ]);
        $row->save();

        $counter = match ($outcome) {
            ImportWriteAction::Create => 'created_count',
            ImportWriteAction::Update => 'updated_count',
            ImportWriteAction::Skip => 'skipped_count',
            ImportWriteAction::Fail => 'failed_count',
        };
        $affected = DB::connection($connectionName)->table('smart_imports')
            ->where('id', $session->getKey())
            ->where('confirmed_revision', $session->confirmed_revision)
            ->where('staging_revision', $session->confirmed_revision)
            ->where('current_attempt', $session->current_attempt)
            ->whereIn('status', [ImportStatus::Queued->value, ImportStatus::Importing->value])
            ->whereColumn('processed', '<', 'execution_total')
            ->update([
                'processed' => DB::raw('processed + 1'),
                $counter => DB::raw($counter.' + 1'),
                'heartbeat_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);

        if ($affected !== 1) {
            $currentStatus = DB::connection($connectionName)->table('smart_imports')
                ->where('id', $session->getKey())
                ->lockForUpdate()
                ->value('status');

            if ($currentStatus === ImportStatus::Cancelling->value) {
                throw new ImportCancellationRequested();
            }

            throw new DomainException('The import counter could not be updated for this confirmed row.');
        }
    }

    private function safeMessage(string $code): string
    {
        return match ($code) {
            'duplicate_in_file' => 'Another staged row has the same configured matching key.',
            'target_changed_after_preview' => 'The target record changed after the preview was confirmed.',
            'target_match_ambiguous' => 'The configured matching key identifies more than one target record.',
            'target_unique_conflict' => 'A target record conflicted with a database uniqueness rule.',
            'staged_values_changed' => 'The staged values no longer match the confirmed row data.',
            'stale_preview' => 'The saved row preview does not match the confirmed import revision.',
            'validation_failed' => 'The row did not pass validation at execution time.',
            default => 'The row could not be processed under the confirmed import rules.',
        };
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '23505'
            || $driverCode === 1062
            || str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry');
    }

    /** @return array{execution_status: string, target_type: string|null, target_id: string|null, already_processed: bool} */
    private function result(?ImportRow $row, bool $alreadyProcessed): array
    {
        if (! $row instanceof ImportRow) {
            throw new DomainException('The row outcome could not be loaded after processing.');
        }

        $history = $row->execution_history ?? [];
        $lastOutcome = $history === [] ? [] : $history[array_key_last($history)];

        return [
            'execution_status' => (string) $row->execution_status,
            'target_type' => $lastOutcome['target_type'] ?? $row->resolved_target_type,
            'target_id' => $lastOutcome['target_id'] ?? $row->resolved_target_id,
            'already_processed' => $alreadyProcessed,
        ];
    }
}
