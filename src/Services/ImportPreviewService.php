<?php

namespace SmartImport\Services;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\ImportWriteAction;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use Throwable;

final class ImportPreviewService
{
    private const ROW_BATCH_SIZE = 250;

    private const MAX_PAGE_SIZE = 250;

    private const REDACTED = '[redacted]';

    public function __construct(
        private ImportCoordinator $coordinator,
        private ImportAuthorization $authorization,
        private ImporterRegistry $importers,
        private TargetRecordMatcher $targetRecordMatcher,
        private ImportExecutionDispatcher $executionDispatcher,
    ) {}

    /**
     * Recompute the read-only preview against current target data and persist row previews.
     * Sensitive values are encrypted in storage and revealed only through rows() after a Gate check.
     *
     * @return array{staging_revision: int, total: int, create: int, update: int, skip: int, fail: int}
     */
    public function generate(string $sessionId, Authenticatable $actor): array
    {
        $session = $this->reviewSession($sessionId, $actor, true);
        $definition = $this->definition($session);
        $mappingRevision = $session->mapping_revision;
        $stagingRevision = $session->staging_revision;
        $counts = [
            'staging_revision' => $stagingRevision,
            'total' => $session->rows()->count(),
            'create' => 0,
            'update' => 0,
            'skip' => 0,
            'fail' => 0,
        ];

        $session->rows()->orderBy('id')->chunkById(self::ROW_BATCH_SIZE, function ($rows) use (
            $session,
            $definition,
            $mappingRevision,
            $stagingRevision,
            &$counts,
        ): void {
            $normalizedRows = [];

            foreach ($rows as $row) {
                $normalizedRows[] = in_array($row->validation_status, ['valid', 'warning'], true)
                    ? ($row->normalized_values ?? [])
                    : [];
            }

            $matches = $this->targetRecordMatcher->matchBatch($definition, $normalizedRows);
            $decisions = [];
            $targetIds = [];

            foreach ($rows as $index => $row) {
                $preview = $this->previewDecision($definition, $row, $matches[$index]);
                $decisions[$index] = $preview;

                if ($preview['action'] === ImportWriteAction::Update->value && $preview['target_id'] !== null) {
                    $targetIds[] = $preview['target_id'];
                }
            }

            $targets = $this->loadTargets($definition, $rows, $decisions, $targetIds);
            $persist = [];

            foreach ($rows as $index => $row) {
                $preview = $decisions[$index];

                if ($preview['action'] === ImportWriteAction::Update->value) {
                    $target = $targets[$preview['target_id']] ?? null;

                    if (! $target instanceof Model) {
                        $preview = $this->failedPreview('target_unavailable');
                    } elseif (! $this->targetRecordMatcher->matchesKey($definition, $row->normalized_values ?? [], $target)) {
                        $preview = $this->failedPreview('target_changed_after_match');
                    } else {
                        $preview['changes'] = $this->changes(
                            $definition,
                            $row->normalized_values ?? [],
                            $target,
                        );
                    }
                } elseif ($preview['action'] === ImportWriteAction::Create->value) {
                    $preview['changes'] = $this->changes($definition, $row->normalized_values ?? []);
                }

                $preview['staging_revision'] = $stagingRevision;
                $preview['mapping_revision'] = $mappingRevision;
                $counts[$preview['action']]++;
                $persist[] = [$row, $preview];
            }

            $this->persistBatch($session, $mappingRevision, $stagingRevision, $persist);
        });

        $this->assertRevisionCurrent($session, $mappingRevision, $stagingRevision);

        return $counts;
    }

    /**
     * Return one authorized page of persisted preview rows without exposing staged raw values.
     *
     * @return array{staging_revision: int, page: int, per_page: int, total: int, last_page: int, rows: list<array<string, mixed>>}
     */
    public function rows(
        string $sessionId,
        Authenticatable $actor,
        int $page = 1,
        int $perPage = 50,
    ): array {
        if ($page < 1 || $perPage < 1 || $perPage > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException(sprintf('Preview pagination must use a positive page and 1 to %d rows per page.', self::MAX_PAGE_SIZE));
        }

        $session = $this->reviewSession($sessionId, $actor);
        $definition = $this->definition($session);
        $query = $session->rows()->orderBy('source_row_number')->orderBy('id');
        $total = (clone $query)->count();

        if ((clone $query)->whereNull('preview')->exists()) {
            throw new DomainException('Generate a current preview before reading preview rows.');
        }

        $pageRows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get(['id', 'source_row_number', 'validation_status', 'preview']);
        $sensitiveAccess = $this->sensitiveFieldAccess($definition, $session, $actor);
        $output = [];

        foreach ($pageRows as $row) {
            $preview = $row->preview;

            if (! is_array($preview)
                || ($preview['staging_revision'] ?? null) !== $session->staging_revision
                || ($preview['mapping_revision'] ?? null) !== $session->mapping_revision) {
                throw new DomainException('The saved preview is stale; generate a new preview before continuing.');
            }

            $output[] = [
                'id' => (int) $row->getKey(),
                'source_row_number' => $row->source_row_number,
                'validation_status' => $row->validation_status,
                'preview' => $this->revealSensitiveValues($preview, $sensitiveAccess),
            ];
        }

        return [
            'staging_revision' => $session->staging_revision,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'rows' => $output,
        ];
    }

    /**
     * Read one terminal import row as a safe history projection, including authorized diffs and target references.
     *
     * The stored preview is intentionally used directly so a removed or upgraded importer does not make
     * historical row outcomes unreadable. Only the persisted sensitive flag is used to request field access;
     * encrypted values still pass through the same Gate and decryption path as the review preview.
     *
     * @return array<string, mixed>
     */
    public function historyRow(string $sessionId, int $rowId, Authenticatable $actor): array
    {
        $session = $this->coordinator->get($sessionId, $actor);

        if (! in_array($session->status, [ImportStatus::Completed, ImportStatus::Failed, ImportStatus::Cancelled], true)) {
            throw new DomainException('Row history is available only for a finished import attempt.');
        }

        $row = ImportRow::query()
            ->where('import_id', $sessionId)
            ->whereKey($rowId)
            ->firstOrFail();
        $preview = is_array($row->preview) ? $row->preview : [];
        $preview['changes'] = is_array($preview['changes'] ?? null) ? $preview['changes'] : [];
        $sensitiveAccess = $this->historySensitiveFieldAccess($preview, $session, $actor);
        $preview = $this->revealSensitiveValues($preview, $sensitiveAccess);
        $changes = [];

        foreach ($preview['changes'] as $change) {
            if (! is_array($change) || ! is_string($change['field'] ?? null) || ! is_string($change['label'] ?? null)) {
                continue;
            }

            $changes[] = [
                'field' => $change['field'],
                'label' => $change['label'],
                'before_exists' => (bool) ($change['before_exists'] ?? false),
                'before' => $this->historyValue($change['before'] ?? null),
                'after' => $this->historyValue($change['after'] ?? null),
                'sensitive' => (bool) ($change['sensitive'] ?? false),
                'redacted' => (bool) ($change['redacted'] ?? false),
            ];
        }

        $outcomes = collect($row->execution_history ?? [])
            ->filter(static fn (mixed $outcome): bool => is_array($outcome))
            ->map(static function (array $outcome): array {
                return [
                    'attempt' => is_numeric($outcome['attempt'] ?? null) ? (int) $outcome['attempt'] : null,
                    'status' => is_string($outcome['status'] ?? null) ? $outcome['status'] : 'unknown',
                    'target_type' => is_string($outcome['target_type'] ?? null) ? $outcome['target_type'] : null,
                    'target_id' => is_scalar($outcome['target_id'] ?? null) ? (string) $outcome['target_id'] : null,
                    'error_code' => is_string($outcome['error_code'] ?? null) ? $outcome['error_code'] : null,
                    'completed_at' => is_string($outcome['completed_at'] ?? null) ? $outcome['completed_at'] : null,
                ];
            })
            ->values()
            ->all();
        $lastOutcome = $outcomes === [] ? [] : $outcomes[array_key_last($outcomes)];

        return [
            'id' => (int) $row->getKey(),
            'source_sheet' => $row->source_sheet,
            'source_row_number' => (int) $row->source_row_number,
            'validation_status' => (string) $row->validation_status,
            'execution_status' => (string) $row->execution_status,
            'change_type' => is_string($row->change_type) ? $row->change_type : null,
            'planned_action' => is_string($preview['action'] ?? null) ? $preview['action'] : null,
            'warning_count' => is_array($row->warnings) ? count($row->warnings) : 0,
            'validation_errors' => collect($row->validation_errors ?? [])
                ->filter(static fn (mixed $error): bool => is_array($error))
                ->map(fn (array $error): array => [
                    'field' => is_string($error['field'] ?? null) ? $error['field'] : null,
                    'code' => is_string($error['code'] ?? null) ? $error['code'] : 'validation_failed',
                    'source_row' => is_numeric($error['source_row'] ?? null) ? (int) $error['source_row'] : (int) $row->source_row_number,
                    'message' => $this->safeValidationMessage(is_string($error['code'] ?? null) ? $error['code'] : 'validation_failed'),
                ])
                ->values()
                ->all(),
            'execution_error' => $lastOutcome['error_code'] === null ? null : [
                'code' => $lastOutcome['error_code'],
                'message' => 'The row could not be processed under the confirmed import rules.',
            ],
            'preview_target' => [
                'type' => is_string($preview['target_type'] ?? null) ? $preview['target_type'] : $row->resolved_target_type,
                'id' => is_scalar($preview['target_id'] ?? null) ? (string) $preview['target_id'] : $row->resolved_target_id,
            ],
            'outcome_target' => [
                'type' => $lastOutcome['target_type'],
                'id' => $lastOutcome['target_id'],
            ],
            'attempts' => $outcomes,
            'changes' => $changes,
        ];
    }

    /** Confirm exactly the previewed mapping/staging revision and freeze it for execution. */
    public function confirm(string $sessionId, Authenticatable $actor): ImportSession
    {
        DB::transaction(function () use ($sessionId, $actor): void {
            $session = $this->authorization
                ->applyActorScope(ImportSession::query()->lockForUpdate(), $actor)
                ->whereKey($sessionId)
                ->firstOrFail();

            $this->authorization->authorize('view', $actor, $session);
            $this->authorization->authorize('queue', $actor, $session);
            $this->executionDispatcher->assertQueueCanRunAsynchronously();

            if ($session->status !== ImportStatus::ReviewReady) {
                throw new DomainException('Only a review-ready import can be confirmed.');
            }

            $this->definition($session);
            $total = $session->rows()->count();

            if ($total === 0) {
                throw new DomainException('An import with no staged rows cannot be confirmed.');
            }

            $invalidPreview = false;

            $session->rows()->orderBy('id')->chunkById(self::ROW_BATCH_SIZE, function ($rows) use ($session, &$invalidPreview) {
                foreach ($rows as $row) {
                    $preview = $row->preview;

                    if (! is_array($preview)
                        || ($preview['staging_revision'] ?? null) !== $session->staging_revision
                        || ($preview['mapping_revision'] ?? null) !== $session->mapping_revision
                        || ImportWriteAction::tryFrom((string) ($preview['action'] ?? '')) === null) {
                        $invalidPreview = true;

                        return false;
                    }
                }
            });

            if ($invalidPreview) {
                throw new DomainException('Generate a complete preview for the current import revision before confirming.');
            }

            $attemptId = $session->current_attempt + 1;
            $queuedAt = now();
            $attempts = $session->attempt_history ?? [];
            $attempts[] = [
                'attempt' => $attemptId,
                'confirmed_revision' => $session->staging_revision,
                'execution_total' => $total,
                'queued_at' => $queuedAt->toISOString(),
            ];
            $session->forceFill([
                'confirmed_revision' => $session->staging_revision,
                'execution_total' => $total,
                'current_attempt' => $attemptId,
                'recovery_count' => 0,
                'attempt_history' => $attempts,
                'queued_at' => $queuedAt,
                'heartbeat_at' => now(),
            ]);
            $session->transitionTo(ImportStatus::Queued);
            $session->save();

            $session->getConnection()->afterCommit(fn () => $this->executionDispatcher->dispatchConfirmed(
                (string) $session->getKey(),
                $attemptId,
                $session->confirmed_revision,
            ));
        }, 3);

        return ImportSession::query()->findOrFail($sessionId);
    }

    private function reviewSession(string $sessionId, Authenticatable $actor, bool $forGeneration = false): ImportSession
    {
        $session = $this->coordinator->get($sessionId, $actor);

        $readableStatuses = [
            ImportStatus::ReviewReady,
            ImportStatus::Queued,
            ImportStatus::Importing,
            ImportStatus::Cancelling,
            ImportStatus::Completed,
            ImportStatus::Failed,
            ImportStatus::Cancelled,
        ];

        if ($forGeneration ? $session->status !== ImportStatus::ReviewReady : ! in_array($session->status, $readableStatuses, true)) {
            throw new DomainException('A preview is available only while the import is ready for review.');
        }

        return $session;
    }

    private function definition(ImportSession $session): ImportDefinition
    {
        if ($session->importer_key === null || $session->importer_version === null) {
            throw new DomainException('The staged import configuration is incomplete.');
        }

        $definition = $this->importers->get($session->importer_key);

        if ($definition->version() !== $session->importer_version) {
            throw new DomainException('The importer definition changed after staging; restage before previewing.');
        }

        return $definition;
    }

    /** @return array<string, mixed> */
    private function previewDecision(ImportDefinition $definition, ImportRow $row, array $match): array
    {
        if (! in_array($row->validation_status, ['valid', 'warning'], true)) {
            $firstError = ($row->validation_errors ?? [])[0] ?? null;

            return $this->failedPreview(is_array($firstError) && is_string($firstError['code'] ?? null)
                ? $firstError['code']
                : 'validation_failed');
        }

        $decision = $definition->writePolicy()->decisionFor($match['change_type']);

        return [
            'action' => $decision['action']->value,
            'reason' => $decision['reason'],
            'target_type' => $match['target_type'],
            'target_id' => $match['target_id'],
            'changes' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function failedPreview(string $reason): array
    {
        return [
            'action' => ImportWriteAction::Fail->value,
            'reason' => $reason,
            'target_type' => null,
            'target_id' => null,
            'changes' => [],
        ];
    }

    /**
     * @param iterable<ImportRow> $rows
     * @param array<int, array<string, mixed>> $decisions
     * @param list<string> $targetIds
     * @return array<string, Model>
     */
    private function loadTargets(ImportDefinition $definition, iterable $rows, array $decisions, array $targetIds): array
    {
        $targetIds = array_values(array_unique($targetIds));

        if ($targetIds === []) {
            return [];
        }

        $modelClass = $definition->targetModel();
        $target = new $modelClass();
        $columns = [$target->qualifyColumn($target->getKeyName())];

        foreach ($rows as $index => $row) {
            if (($decisions[$index]['action'] ?? null) !== ImportWriteAction::Update->value) {
                continue;
            }

            foreach (array_keys($row->normalized_values ?? []) as $fieldName) {
                $columns[] = $target->qualifyColumn($fieldName);
            }
        }

        $columns = array_values(array_unique($columns));
        $records = [];

        foreach (array_chunk($targetIds, 500) as $targetIdChunk) {
            foreach ($target->newQuery()->select($columns)->whereKey($targetIdChunk)->cursor() as $record) {
                $records[(string) $record->getKey()] = $record;
            }
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $normalizedValues
     * @return list<array<string, mixed>>
     */
    private function changes(ImportDefinition $definition, array $normalizedValues, ?Model $target = null): array
    {
        $fields = [];

        foreach ($definition->fields() as $field) {
            if (! $field instanceof ImportField) {
                throw new DomainException('The importer field definition changed after registration.');
            }

            $fields[$field->name] = $field;
        }

        $changes = [];

        foreach ($normalizedValues as $fieldName => $after) {
            if (! is_string($fieldName) || ! array_key_exists($fieldName, $fields)) {
                throw new DomainException('The staged row contains a field that is not allowed by its importer.');
            }

            $field = $fields[$fieldName];
            $beforeExists = $target !== null;
            $before = $target?->getRawOriginal($fieldName);

            if ($target !== null && $this->sameValue($before, $after)) {
                continue;
            }

            $change = [
                'field' => $field->name,
                'label' => $field->label,
                'type' => $field->type,
                'before_exists' => $beforeExists,
                'sensitive' => $field->sensitive,
                'before' => $before,
                'after' => $after,
            ];

            if ($field->sensitive) {
                $change['before_ciphertext'] = Crypt::encryptString($this->encodeValue($before));
                $change['after_ciphertext'] = Crypt::encryptString($this->encodeValue($after));
                $change['before'] = self::REDACTED;
                $change['after'] = self::REDACTED;
                $change['redacted'] = true;
            }

            $changes[] = $change;
        }

        return $changes;
    }

    private function sameValue(mixed $before, mixed $after): bool
    {
        if ($before === null || $after === null) {
            return $before === $after;
        }

        if (is_scalar($before) && is_scalar($after)) {
            return (string) $before === (string) $after;
        }

        return $before === $after;
    }

    private function encodeValue(mixed $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new DomainException('A field value cannot be represented safely in the database change preview.', 0, $exception);
        }
    }

    /**
     * @param list<array{0: ImportRow, 1: array<string, mixed>}> $previews
     */
    private function persistBatch(
        ImportSession $session,
        int $mappingRevision,
        int $stagingRevision,
        array $previews,
    ): void {
        if ($previews === []) {
            return;
        }

        DB::transaction(function () use ($session, $mappingRevision, $stagingRevision, $previews): void {
            $current = ImportSession::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($current->status !== ImportStatus::ReviewReady
                || $current->mapping_revision !== $mappingRevision
                || $current->staging_revision !== $stagingRevision) {
                throw new DomainException('The staged import changed while its preview was being generated.');
            }

            $now = now()->toDateTimeString();
            $records = [];

            foreach ($previews as [$row, $preview]) {
                $records[] = [
                    'id' => $row->getKey(),
                    'import_id' => $session->getKey(),
                    'source_sheet' => $row->source_sheet,
                    'source_row_number' => $row->source_row_number,
                    'preview' => json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                    'created_at' => $row->created_at?->toDateTimeString() ?? $now,
                    'updated_at' => $now,
                ];
            }

            ImportRow::query()->upsert($records, ['id'], ['preview', 'updated_at']);
        }, 3);
    }

    private function assertRevisionCurrent(ImportSession $session, int $mappingRevision, int $stagingRevision): void
    {
        $current = ImportSession::query()->findOrFail($session->getKey());

        if ($current->status !== ImportStatus::ReviewReady
            || $current->mapping_revision !== $mappingRevision
            || $current->staging_revision !== $stagingRevision) {
            throw new DomainException('The staged import changed while its preview was being generated.');
        }
    }

    /** @return array<string, bool> */
    private function sensitiveFieldAccess(ImportDefinition $definition, ImportSession $session, Authenticatable $actor): array
    {
        $access = [];

        foreach ($definition->fields() as $field) {
            if ($field instanceof ImportField && $field->sensitive) {
                $access[$field->name] = $this->authorization->allows(
                    'viewSensitiveImportFields',
                    $actor,
                    [$session, $field],
                );
            }
        }

        return $access;
    }

    /** @param array<string, mixed> $preview
     *  @return array<string, bool>
     */
    private function historySensitiveFieldAccess(array $preview, ImportSession $session, Authenticatable $actor): array
    {
        $access = [];

        foreach ($preview['changes'] ?? [] as $change) {
            if (! is_array($change)
                || ! ($change['sensitive'] ?? false)
                || ! is_string($change['field'] ?? null)
                || ! is_string($change['label'] ?? null)) {
                continue;
            }

            try {
                $field = new ImportField(
                    $change['field'],
                    $change['label'],
                    is_string($change['type'] ?? null) ? $change['type'] : 'string',
                    sensitive: true,
                );
            } catch (InvalidArgumentException) {
                $access[$change['field']] = false;

                continue;
            }

            $access[$field->name] = $this->authorization->allows(
                'viewSensitiveImportFields',
                $actor,
                [$session, $field],
            );
        }

        return $access;
    }

    private function historyValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_string($value)) {
            return mb_strlen($value, 'UTF-8') > 2000
                ? mb_substr($value, 0, 2000, 'UTF-8').'…'
                : $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        try {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '[value unavailable]';
        }

        return mb_strlen($encoded, 'UTF-8') > 2000
            ? mb_substr($encoded, 0, 2000, 'UTF-8').'…'
            : $encoded;
    }

    private function safeValidationMessage(string $code): string
    {
        return match ($code) {
            'transform_failed' => 'The value could not be transformed for this field.',
            'duplicate_in_file' => 'This value is part of a duplicate key in another file row.',
            'target_match_ambiguous' => 'This key matches more than one existing target record.',
            default => 'This field did not pass the configured validation rules.',
        };
    }

    /** @param array<string, bool> $sensitiveAccess
     *  @return array<string, mixed>
     */
    private function revealSensitiveValues(array $preview, array $sensitiveAccess): array
    {
        foreach ($preview['changes'] ?? [] as $index => $change) {
            if (! is_array($change) || ! ($change['sensitive'] ?? false)) {
                continue;
            }

            if (! ($sensitiveAccess[$change['field'] ?? ''] ?? false)) {
                $preview['changes'][$index]['before'] = self::REDACTED;
                $preview['changes'][$index]['after'] = self::REDACTED;
                $preview['changes'][$index]['redacted'] = true;
            } else {
                try {
                    $preview['changes'][$index]['before'] = json_decode(
                        Crypt::decryptString((string) ($change['before_ciphertext'] ?? '')),
                        true,
                        flags: JSON_THROW_ON_ERROR,
                    );
                    $preview['changes'][$index]['after'] = json_decode(
                        Crypt::decryptString((string) ($change['after_ciphertext'] ?? '')),
                        true,
                        flags: JSON_THROW_ON_ERROR,
                    );
                    $preview['changes'][$index]['redacted'] = false;
                } catch (Throwable) {
                    $preview['changes'][$index]['before'] = self::REDACTED;
                    $preview['changes'][$index]['after'] = self::REDACTED;
                    $preview['changes'][$index]['redacted'] = true;
                }
            }

            unset(
                $preview['changes'][$index]['before_ciphertext'],
                $preview['changes'][$index]['after_ciphertext'],
            );
        }

        return $preview;
    }
}
