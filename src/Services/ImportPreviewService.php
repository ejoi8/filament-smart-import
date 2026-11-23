<?php

namespace SmartImport\Services;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportExecutionContext;
use SmartImport\Data\ImportField;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\ImportWriteAction;
use SmartImport\Jobs\GenerateImportPreviewJob;
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
        private ImportValueComparator $valueComparator,
        private ImportExecutionContext $executionContext,
        private ImportPayloadCodec $payloadCodec,
        private ImportAuditService $audit,
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
        $this->authorization->authorize('update', $actor, $session);
        $definition = $this->definition($session);
        $this->authorization->authorize('useImporter', $actor, $definition);
        $generationId = (string) Str::uuid();
        $mappingRevision = $session->mapping_revision;
        $stagingRevision = $session->staging_revision;
        $total = $session->rows()->count();

        $this->markPreviewBuilding($session, $mappingRevision, $stagingRevision, $total, $generationId);

        try {
            return $this->executionContext->run(
                $session,
                fn (): array => $this->buildPreview($session, $mappingRevision, $stagingRevision, $generationId),
            );
        } catch (Throwable $exception) {
            $this->failPreview($sessionId, $mappingRevision, $stagingRevision, $generationId);

            throw $exception;
        }
    }

    /**
     * Generate small previews in the request and queue larger previews on the configured async queue.
     * Both paths call the same bounded preview builder and freeze the same revisions.
     *
     * @return array{queued: bool, already_queued: bool, summary: array<string, int>|null}
     */
    public function generateOrQueue(string $sessionId, Authenticatable $actor, int $expectedStagingRevision): array
    {
        $session = $this->reviewSession($sessionId, $actor, true);
        $this->authorization->authorize('update', $actor, $session);
        $definition = $this->definition($session);
        $this->authorization->authorize('useImporter', $actor, $definition);

        if ($session->staging_revision !== $expectedStagingRevision) {
            throw new DomainException('This import changed after you opened the review. Refresh before generating a preview.');
        }

        $total = $session->rows()->count();
        $threshold = (int) config('smart-import.preview.async_threshold_rows', 1000);

        if ($threshold < 1 || $threshold > 100000) {
            throw new DomainException('Smart Import preview async threshold must be between 1 and 100000 rows.');
        }

        if ($total < $threshold) {
            $summary = $this->generate($sessionId, $actor);

            return ['queued' => false, 'already_queued' => false, 'summary' => $summary];
        }

        $this->executionDispatcher->assertQueueCanRunAsynchronously();
        $generationId = (string) Str::uuid();
        $mappingRevision = $session->mapping_revision;
        $stagingRevision = $session->staging_revision;
        $claim = DB::transaction(function () use (
            $sessionId,
            $actor,
            $expectedStagingRevision,
            $mappingRevision,
            $stagingRevision,
            $total,
            $generationId,
        ): array {
            $current = $this->authorization
                ->applyActorScope(ImportSession::query()->lockForUpdate(), $actor)
                ->whereKey($sessionId)
                ->firstOrFail();
            $this->authorization->authorize('view', $actor, $current);
            $this->authorization->authorize('update', $actor, $current);

            if ($current->status !== ImportStatus::ReviewReady
                || $current->staging_revision !== $expectedStagingRevision
                || $current->mapping_revision !== $mappingRevision) {
                throw new DomainException('This import changed before its preview could be queued. Refresh the review and try again.');
            }

            $options = $current->options ?? [];

            if (($options['preview_state'] ?? null) === 'building'
                && ($options['preview_revision'] ?? null) === $stagingRevision
                && is_string($options['preview_generation_id'] ?? null)) {
                return ['generation_id' => $options['preview_generation_id'], 'already_queued' => true];
            }

            $this->writePreviewBuildingState($current, $mappingRevision, $stagingRevision, $total, $generationId);

            return ['generation_id' => $generationId, 'already_queued' => false];
        }, 3);

        if (! $claim['already_queued']) {
            try {
                $this->dispatchPreview((string) $session->getKey(), $mappingRevision, $stagingRevision, $claim['generation_id']);
            } catch (Throwable $exception) {
                $this->failPreview($sessionId, $mappingRevision, $stagingRevision, $claim['generation_id']);

                throw $exception;
            }
        }

        return ['queued' => true, 'already_queued' => $claim['already_queued'], 'summary' => null];
    }

    /** Run a queued preview only when its persisted generation identity is still current. */
    public function generateQueued(string $sessionId, int $mappingRevision, int $stagingRevision, string $generationId): void
    {
        $session = ImportSession::query()->find($sessionId);

        if (! $this->isCurrentGeneration($session, $mappingRevision, $stagingRevision, $generationId)) {
            return;
        }

        try {
            $this->executionContext->run(
                $session,
                fn (): array => $this->buildPreview($session, $mappingRevision, $stagingRevision, $generationId),
            );
        } catch (Throwable $exception) {
            $this->failPreview($sessionId, $mappingRevision, $stagingRevision, $generationId);

            throw $exception;
        }
    }

    /** Mark only the matching durable preview generation as failed. */
    public function failQueuedPreview(string $sessionId, int $mappingRevision, int $stagingRevision, string $generationId): void
    {
        $this->failPreview($sessionId, $mappingRevision, $stagingRevision, $generationId);
    }

    /** Requeue one stale preview under a new generation identity so an old worker cannot publish over it. */
    public function recoverStalePreview(string $sessionId, mixed $cutoff, int $maxRecoveries, bool $dryRun): string
    {
        if (! $dryRun) {
            try {
                $this->executionDispatcher->assertQueueCanRunAsynchronously();
            } catch (Throwable) {
                $this->failUnavailablePreviewQueue($sessionId);

                return 'failed';
            }
        }

        $result = DB::transaction(function () use ($sessionId, $cutoff, $maxRecoveries, $dryRun): array {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if ($session === null || $session->status !== ImportStatus::ReviewReady) {
                return ['outcome' => 'skipped'];
            }

            $options = $session->options ?? [];
            $heartbeat = $options['preview_heartbeat_at'] ?? $session->heartbeat_at;

            if (($options['preview_state'] ?? null) !== 'building'
                || ($options['preview_revision'] ?? null) !== $session->staging_revision
                || ($options['preview_mapping_revision'] ?? null) !== $session->mapping_revision
                || ! is_string($options['preview_generation_id'] ?? null)
                || $heartbeat === null
                || Carbon::parse($heartbeat)->greaterThanOrEqualTo($cutoff)) {
                return ['outcome' => 'skipped'];
            }

            $recoveries = (int) ($options['preview_recovery_count'] ?? 0);

            if ($recoveries >= $maxRecoveries) {
                if ($dryRun) {
                    return ['outcome' => 'would_fail'];
                }

                $options['preview_state'] = 'failed';
                $options['preview_error'] = 'The preview recovery limit was reached. Queue workers or the source file may need attention.';
                $options['preview_heartbeat_at'] = now()->toIso8601String();
                $session->forceFill(['options' => $options, 'heartbeat_at' => now()])->save();

                return ['outcome' => 'failed'];
            }

            if ($dryRun) {
                return ['outcome' => 'would_recover'];
            }

            $generationId = (string) Str::uuid();
            $options['preview_generation_id'] = $generationId;
            $options['preview_recovery_count'] = $recoveries + 1;
            $options['preview_processed'] = 0;
            $options['preview_summary'] = null;
            $options['preview_error'] = null;
            $options['preview_heartbeat_at'] = now()->toIso8601String();
            $session->rows()->update(['preview' => null]);
            $session->forceFill(['options' => $options, 'heartbeat_at' => now()])->save();

            return [
                'outcome' => 'preview_requeued',
                'mapping_revision' => $session->mapping_revision,
                'staging_revision' => $session->staging_revision,
                'generation_id' => $generationId,
            ];
        }, 3);

        if ($result['outcome'] !== 'preview_requeued') {
            return $result['outcome'];
        }

        try {
            $this->dispatchPreview(
                $sessionId,
                $result['mapping_revision'],
                $result['staging_revision'],
                $result['generation_id'],
            );
        } catch (Throwable) {
            $this->failPreview(
                $sessionId,
                $result['mapping_revision'],
                $result['staging_revision'],
                $result['generation_id'],
            );

            return 'failed';
        }

        return 'preview_requeued';
    }

    /**
     * @return array{staging_revision: int, total: int, create: int, update: int, skip: int, fail: int}
     */
    private function buildPreview(ImportSession $session, int $mappingRevision, int $stagingRevision, string $generationId): array
    {
        if (! $this->isCurrentGeneration($session->fresh(), $mappingRevision, $stagingRevision, $generationId)) {
            throw new DomainException('The staged import changed before its preview worker started.');
        }

        $definition = $this->definition($session);
        $counts = [
            'staging_revision' => $stagingRevision,
            'total' => $session->rows()->count(),
            'create' => 0,
            'update' => 0,
            'skip' => 0,
            'fail' => 0,
        ];

        // A queued retry recomputes the full immutable revision. Partial previews are never confirmable.
        $this->restartPreviewRun($session, $mappingRevision, $stagingRevision, $generationId, $counts['total']);

        $session->rows()->orderBy('id')->chunkById(self::ROW_BATCH_SIZE, function ($rows) use (
            $session,
            $definition,
            $mappingRevision,
            $stagingRevision,
            $generationId,
            &$counts,
        ): void {
            // Eloquent collections are keyed by model primary key; these arrays are position-indexed.
            $rows = $rows->values();
            $normalizedRows = [];

            foreach ($rows as $row) {
                $normalizedRows[] = in_array($row->validation_status, ['valid', 'warning'], true)
                    ? $this->payloadCodec->reveal($row->normalized_values)
                    : [];
            }

            $matches = $this->targetRecordMatcher->matchBatch($definition, $normalizedRows);
            $decisions = [];
            $targetIds = [];

            foreach ($rows as $index => $row) {
                $preview = $this->previewDecision($definition, $row, $matches[$index]);
                $normalizedValues = $this->payloadCodec->reveal($row->normalized_values);
                $decisions[$index] = $preview;

                if ($preview['action'] === ImportWriteAction::Update->value && $preview['target_id'] !== null) {
                    $targetIds[] = $preview['target_id'];
                }
            }

            $targets = $this->loadTargets($definition, $rows, $decisions, $targetIds);
            $persist = [];

            foreach ($rows as $index => $row) {
                $preview = $decisions[$index];
                $normalizedValues = $this->payloadCodec->reveal($row->normalized_values);

                if ($preview['action'] === ImportWriteAction::Update->value) {
                    $target = $targets[$preview['target_id']] ?? null;

                    if (! $target instanceof Model) {
                        $preview = $this->failedPreview('target_unavailable');
                    } elseif (! $this->targetRecordMatcher->matchesKey($definition, $normalizedValues, $target)) {
                        $preview = $this->failedPreview('target_changed_after_match');
                    } else {
                        $preview['target_snapshot'] = [
                            'version' => 1,
                            'token' => $this->valueComparator->targetSnapshotToken(
                                $target,
                                array_keys($normalizedValues),
                            ),
                        ];
                        $preview['changes'] = $this->changes(
                            $definition,
                            $normalizedValues,
                            $target,
                        );

                        if ($preview['changes'] === []) {
                            $preview['action'] = ImportWriteAction::Skip->value;
                            $preview['reason'] = 'unchanged';
                        }
                    }
                } elseif ($preview['action'] === ImportWriteAction::Create->value) {
                    $preview['changes'] = $this->changes($definition, $normalizedValues);
                }

                $preview['staging_revision'] = $stagingRevision;
                $preview['mapping_revision'] = $mappingRevision;
                $counts[$preview['action']]++;
                $persist[] = [$row, $preview];
            }

            $this->persistBatch($session, $mappingRevision, $stagingRevision, $generationId, $persist);
        });

        $this->assertRevisionCurrent($session, $mappingRevision, $stagingRevision, $generationId);
        $this->markPreviewReady($session, $mappingRevision, $stagingRevision, $generationId, $counts);

        return $counts;
    }

    /**
     * Return one authorized page of persisted preview rows without exposing staged raw values.
     *
     * @return array{
     *     staging_revision: int,
     *     field_labels: array<string, string>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     last_page: int,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function rows(
        string $sessionId,
        Authenticatable $actor,
        int $page = 1,
        int $perPage = 50,
        ?string $validationStatus = null,
        ?string $changeType = null,
        bool $duplicatesOnly = false,
        bool $allowMissingPreview = false,
    ): array {
        if ($page < 1 || $perPage < 1 || $perPage > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException(sprintf('Preview pagination must use a positive page and 1 to %d rows per page.', self::MAX_PAGE_SIZE));
        }

        if ($validationStatus !== null && ! in_array($validationStatus, ['valid', 'invalid', 'warning'], true)) {
            throw new InvalidArgumentException('The requested row validation status is not supported.');
        }

        if ($changeType !== null && ! in_array($changeType, ['new', 'matched', 'ambiguous'], true)) {
            throw new InvalidArgumentException('The requested row change type is not supported.');
        }

        $session = $this->reviewSession($sessionId, $actor);
        $definition = $this->definition($session);
        $this->authorization->authorize('useImporter', $actor, $definition);
        $query = $session->rows()->orderBy('source_row_number')->orderBy('id');

        if ($validationStatus !== null) {
            $query->where('validation_status', $validationStatus);
        }

        if ($changeType !== null) {
            $query->where('change_type', $changeType);
        }

        if ($duplicatesOnly) {
            $query->whereJsonContains('validation_errors', ['code' => 'duplicate_in_file']);
        }

        $total = (clone $query)->count();

        if (! $allowMissingPreview && $session->rows()->whereNull('preview')->exists()) {
            throw new DomainException('Generate a current preview before reading preview rows.');
        }

        $pageRows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get([
                'id',
                'source_row_number',
                'validation_status',
                'execution_status',
                'change_type',
                'mapped_values',
                'validation_errors',
                'warnings',
                'preview',
            ]);
        $sensitiveAccess = $this->sensitiveFieldAccess($definition, $session, $actor);
        $fields = [];
        $fieldLabels = [];

        foreach ($definition->fields() as $field) {
            if ($field instanceof ImportField) {
                $fields[$field->name] = $field;
                $fieldLabels[$field->name] = $field->label;
            }
        }

        $output = [];

        foreach ($pageRows as $row) {
            $preview = $row->preview;

            if (is_array($preview)
                && (($preview['staging_revision'] ?? null) !== $session->staging_revision
                    || ($preview['mapping_revision'] ?? null) !== $session->mapping_revision)) {
                throw new DomainException('The saved preview is stale; generate a new preview before continuing.');
            }

            if (! is_array($preview) && ! $allowMissingPreview) {
                throw new DomainException('Generate a current preview before reading preview rows.');
            }

            $validationErrors = collect($row->validation_errors ?? [])
                ->filter(static fn (mixed $error): bool => is_array($error))
                ->map(fn (array $error): array => [
                    'field' => is_string($error['field'] ?? null) ? $error['field'] : null,
                    'code' => is_string($error['code'] ?? null) ? $error['code'] : 'validation_failed',
                    'message' => $this->safeValidationMessage(is_string($error['code'] ?? null) ? $error['code'] : 'validation_failed'),
                ])
                ->values()
                ->all();
            $mappedValues = [];
            $storedMappedValues = $row->mapped_values;
            $storedFieldNames = $this->payloadCodec->keys($storedMappedValues);
            $readableFieldNames = array_keys(array_filter(
                $fields,
                fn (ImportField $field): bool => ! $field->sensitive || ($sensitiveAccess[$field->name] ?? false),
            ));
            $readableMappedValues = $this->payloadCodec->reveal($storedMappedValues, $readableFieldNames);

            foreach ($storedFieldNames as $fieldName) {
                if (! is_string($fieldName) || ! isset($fields[$fieldName])) {
                    continue;
                }

                $mappedValues[$fieldName] = $fields[$fieldName]->sensitive && ! ($sensitiveAccess[$fieldName] ?? false)
                    ? self::REDACTED
                    : $this->safeReviewValue($readableMappedValues[$fieldName] ?? null);
            }

            $output[] = [
                'id' => (int) $row->getKey(),
                'source_row_number' => $row->source_row_number,
                'validation_status' => $row->validation_status,
                'execution_status' => (string) $row->execution_status,
                'change_type' => is_string($row->change_type) ? $row->change_type : null,
                'mapped_values' => $mappedValues,
                'validation_errors' => $validationErrors,
                'warning_count' => is_array($row->warnings) ? count($row->warnings) : 0,
                'preview' => is_array($preview) ? $this->revealSensitiveValues($preview, $sensitiveAccess) : null,
            ];
        }

        $options = $session->options ?? [];

        return [
            'staging_revision' => $session->staging_revision,
            'preview_state' => ($options['preview_revision'] ?? null) === $session->staging_revision
                && ($options['preview_mapping_revision'] ?? null) === $session->mapping_revision
                ? ($options['preview_state'] ?? null)
                : null,
            'preview_processed' => (int) ($options['preview_processed'] ?? 0),
            'preview_total' => (int) ($options['preview_total'] ?? $session->rows()->count()),
            'preview_summary' => is_array($options['preview_summary'] ?? null) ? $options['preview_summary'] : null,
            'preview_error' => is_string($options['preview_error'] ?? null) ? $options['preview_error'] : null,
            'preview_no_recent_heartbeat' => ($options['preview_state'] ?? null) === 'building'
                && ($options['preview_heartbeat_at'] ?? null) !== null
                && Carbon::parse($options['preview_heartbeat_at'])->lessThan(now()->subSeconds(max(1, (int) config('smart-import.recovery.stale_after_seconds', 900)))),
            'stage_summary' => [
                'valid' => (int) $session->stage_valid,
                'invalid' => (int) $session->stage_invalid,
                'warnings' => (int) $session->stage_warning,
                'excluded' => (int) $session->rows()->where('execution_status', 'excluded')->count(),
            ],
            'field_labels' => $fieldLabels,
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

    /** Confirm exactly the reviewed staging revision and freeze its eligible rows for execution. */
    public function confirm(
        string $sessionId,
        Authenticatable $actor,
        int $expectedStagingRevision,
        bool $excludeInvalidRows = false,
    ): ImportSession
    {
        DB::transaction(function () use ($sessionId, $actor, $expectedStagingRevision, $excludeInvalidRows): void {
            $session = $this->authorization
                ->applyActorScope(ImportSession::query()->lockForUpdate(), $actor)
                ->whereKey($sessionId)
                ->firstOrFail();

            $this->authorization->authorize('view', $actor, $session);
            $this->authorization->authorize('queue', $actor, $session);
            $definition = $this->definition($session);
            $this->authorization->authorize('useImporter', $actor, $definition);

            if ($session->staging_revision !== $expectedStagingRevision) {
                throw new DomainException('This import changed after you reviewed it. Refresh the review before confirming.');
            }

            $options = $session->options ?? [];

            if (($options['preview_state'] ?? null) !== 'ready'
                || ($options['preview_revision'] ?? null) !== $session->staging_revision) {
                throw new DomainException('Generate a complete preview for the current import revision before confirming.');
            }

            $this->executionDispatcher->assertQueueCanRunAsynchronously();

            if ($session->status !== ImportStatus::ReviewReady) {
                throw new DomainException('Only a review-ready import can be confirmed.');
            }

            $total = $session->rows()->count();

            if ($total === 0) {
                throw new DomainException('An import with no staged rows cannot be confirmed.');
            }

            $invalidCount = $session->rows()->where('validation_status', 'invalid')->count();

            if ($excludeInvalidRows && ! $definition->writePolicy()->allowInvalidRowExclusion) {
                throw new DomainException('This importer does not allow invalid rows to be excluded at confirmation.');
            }

            if ($invalidCount > 0 && ! $excludeInvalidRows) {
                throw new DomainException(sprintf(
                    '%d invalid staged row(s) must be corrected before confirmation, or explicitly excluded when the importer allows it.',
                    $invalidCount,
                ));
            }

            $executionTotal = $total - ($excludeInvalidRows ? $invalidCount : 0);

            if ($executionTotal < 1) {
                throw new DomainException('No valid staged rows remain to import after excluding invalid rows.');
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

            if ($excludeInvalidRows && $invalidCount > 0) {
                $session->rows()
                    ->where('validation_status', 'invalid')
                    ->where('execution_status', 'pending')
                    ->update(['execution_status' => 'excluded', 'updated_at' => now()]);
            }

            $attemptId = $session->current_attempt + 1;
            $queuedAt = now();
            $attempts = $session->attempt_history ?? [];
            $attempts[] = [
                'attempt' => $attemptId,
                'confirmed_revision' => $session->staging_revision,
                'execution_total' => $executionTotal,
                'excluded_invalid_count' => $excludeInvalidRows ? $invalidCount : 0,
                'queued_at' => $queuedAt->toISOString(),
            ];
            $session->forceFill([
                'options' => array_merge($session->options ?? [], [
                    'exclude_invalid_rows' => $excludeInvalidRows,
                    'excluded_invalid_row_count' => $excludeInvalidRows ? $invalidCount : 0,
                    'eligible_row_count' => $executionTotal,
                ]),
                'confirmed_revision' => $session->staging_revision,
                'execution_total' => $executionTotal,
                'current_attempt' => $attemptId,
                'recovery_count' => 0,
                'attempt_history' => $attempts,
                'queued_at' => $queuedAt,
                'heartbeat_at' => now(),
            ]);
            $session->transitionTo(ImportStatus::Queued);
            $session->save();
            $this->audit->record($session, 'import.confirmed', $actor, 'operator_confirmed', [
                'confirmed_revision' => (int) $session->confirmed_revision,
                'attempt_id' => (int) $session->current_attempt,
                'execution_total' => (int) $session->execution_total,
                'excluded_count' => (int) ($options['excluded_invalid_row_count'] ?? 0),
            ]);

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

            foreach (array_keys($this->payloadCodec->reveal($row->normalized_values)) as $fieldName) {
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

            if ($target !== null && $this->valueComparator->sameForField($field, $target, $before, $after)) {
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
        string $generationId,
        array $previews,
    ): void {
        if ($previews === []) {
            return;
        }

        DB::transaction(function () use ($session, $mappingRevision, $stagingRevision, $generationId, $previews): void {
            $current = ImportSession::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($current->status !== ImportStatus::ReviewReady
                || $current->mapping_revision !== $mappingRevision
                || $current->staging_revision !== $stagingRevision
                || ! $this->isCurrentGeneration($current, $mappingRevision, $stagingRevision, $generationId)) {
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
            $options = $current->options ?? [];
            $options['preview_processed'] = $current->rows()->whereNotNull('preview')->count();
            $options['preview_heartbeat_at'] = now()->toIso8601String();
            $current->forceFill([
                'options' => $options,
                'heartbeat_at' => now(),
            ])->save();
        }, 3);
    }

    private function assertRevisionCurrent(ImportSession $session, int $mappingRevision, int $stagingRevision, string $generationId): void
    {
        $current = ImportSession::query()->findOrFail($session->getKey());

        if ($current->status !== ImportStatus::ReviewReady
            || $current->mapping_revision !== $mappingRevision
            || $current->staging_revision !== $stagingRevision
            || ! $this->isCurrentGeneration($current, $mappingRevision, $stagingRevision, $generationId)) {
            throw new DomainException('The staged import changed while its preview was being generated.');
        }
    }

    private function markPreviewBuilding(
        ImportSession $session,
        int $mappingRevision,
        int $stagingRevision,
        int $total,
        string $generationId,
    ): void
    {
        DB::transaction(function () use ($session, $mappingRevision, $stagingRevision, $total, $generationId): void {
            $current = ImportSession::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($current->status !== ImportStatus::ReviewReady
                || $current->mapping_revision !== $mappingRevision
                || $current->staging_revision !== $stagingRevision) {
                throw new DomainException('The staged import changed before its preview could be generated.');
            }

            $this->writePreviewBuildingState($current, $mappingRevision, $stagingRevision, $total, $generationId);
        }, 3);
    }

    private function writePreviewBuildingState(
        ImportSession $session,
        int $mappingRevision,
        int $stagingRevision,
        int $total,
        string $generationId,
    ): void {
        $session->rows()->update(['preview' => null]);
        $options = $session->options ?? [];
        $session->forceFill([
            'options' => array_merge($options, [
                'preview_state' => 'building',
                'preview_revision' => $stagingRevision,
                'preview_mapping_revision' => $mappingRevision,
                'preview_generation_id' => $generationId,
                'preview_recovery_count' => 0,
                'preview_processed' => 0,
                'preview_total' => $total,
                'preview_summary' => null,
                'preview_error' => null,
                'preview_heartbeat_at' => now()->toIso8601String(),
            ]),
            'heartbeat_at' => now(),
        ])->save();
    }

    private function restartPreviewRun(
        ImportSession $session,
        int $mappingRevision,
        int $stagingRevision,
        string $generationId,
        int $total,
    ): void {
        DB::transaction(function () use ($session, $mappingRevision, $stagingRevision, $generationId, $total): void {
            $current = ImportSession::query()->lockForUpdate()->findOrFail($session->getKey());

            if (! $this->isCurrentGeneration($current, $mappingRevision, $stagingRevision, $generationId)) {
                throw new DomainException('The preview generation was replaced before processing could start.');
            }

            $options = $current->options ?? [];
            $current->rows()->update(['preview' => null]);
            $options['preview_processed'] = 0;
            $options['preview_total'] = $total;
            $options['preview_summary'] = null;
            $options['preview_error'] = null;
            $options['preview_heartbeat_at'] = now()->toIso8601String();
            $current->forceFill(['options' => $options, 'heartbeat_at' => now()])->save();
        }, 3);
    }

    private function dispatchPreview(string $sessionId, int $mappingRevision, int $stagingRevision, string $generationId): void
    {
        $job = new GenerateImportPreviewJob($sessionId, $mappingRevision, $stagingRevision, $generationId);
        $queue = config('smart-import.queue.name');
        $connection = config('smart-import.queue.connection');

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        $job->afterCommit();
        dispatch($job);
    }

    private function failPreview(string $sessionId, int $mappingRevision, int $stagingRevision, string $generationId): void
    {
        DB::transaction(function () use ($sessionId, $mappingRevision, $stagingRevision, $generationId): void {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if (! $this->isCurrentGeneration($session, $mappingRevision, $stagingRevision, $generationId)) {
                return;
            }

            $options = $session->options ?? [];
            $options['preview_state'] = 'failed';
            $options['preview_error'] = 'The change preview could not be completed. Retry preview generation.';
            $options['preview_heartbeat_at'] = now()->toIso8601String();
            $session->forceFill(['options' => $options, 'heartbeat_at' => now()])->save();
        }, 3);
    }

    private function failUnavailablePreviewQueue(string $sessionId): void
    {
        DB::transaction(function () use ($sessionId): void {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if ($session === null || $session->status !== ImportStatus::ReviewReady) {
                return;
            }

            $options = $session->options ?? [];

            if (($options['preview_state'] ?? null) !== 'building') {
                return;
            }

            $options['preview_state'] = 'failed';
            $options['preview_error'] = 'The configured queue cannot run preview recovery. Check the queue connection and start its worker.';
            $options['preview_heartbeat_at'] = now()->toIso8601String();
            $session->forceFill(['options' => $options, 'heartbeat_at' => now()])->save();
        }, 3);
    }

    private function isCurrentGeneration(
        ?ImportSession $session,
        int $mappingRevision,
        int $stagingRevision,
        string $generationId,
    ): bool {
        if (! $session instanceof ImportSession
            || $session->status !== ImportStatus::ReviewReady
            || $session->mapping_revision !== $mappingRevision
            || $session->staging_revision !== $stagingRevision) {
            return false;
        }

        $options = $session->options ?? [];

        return ($options['preview_state'] ?? null) === 'building'
            && ($options['preview_revision'] ?? null) === $stagingRevision
            && ($options['preview_mapping_revision'] ?? null) === $mappingRevision
            && hash_equals((string) ($options['preview_generation_id'] ?? ''), $generationId);
    }

    /** @param array{staging_revision: int, total: int, create: int, update: int, skip: int, fail: int} $summary */
    private function markPreviewReady(
        ImportSession $session,
        int $mappingRevision,
        int $stagingRevision,
        string $generationId,
        array $summary,
    ): void {
        DB::transaction(function () use ($session, $mappingRevision, $stagingRevision, $generationId, $summary): void {
            $current = ImportSession::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($current->status !== ImportStatus::ReviewReady
                || $current->mapping_revision !== $mappingRevision
                || $current->staging_revision !== $stagingRevision
                || ! $this->isCurrentGeneration($current, $mappingRevision, $stagingRevision, $generationId)) {
                throw new DomainException('The staged import changed while its preview was being generated.');
            }

            $options = $current->options ?? [];
            $current->forceFill([
                'options' => array_merge($options, [
                    'preview_state' => 'ready',
                    'preview_revision' => $stagingRevision,
                    'preview_mapping_revision' => $mappingRevision,
                    'preview_generation_id' => $generationId,
                    'preview_processed' => $summary['total'],
                    'preview_total' => $summary['total'],
                    'preview_summary' => $summary,
                    'preview_error' => null,
                    'preview_heartbeat_at' => now()->toIso8601String(),
                ]),
                'heartbeat_at' => now(),
            ]);
            $current->save();
            $this->audit->record($current, 'preview.generated', reasonCode: 'preview_ready', metadata: [
                'staging_revision' => $stagingRevision,
                'total' => $summary['total'],
                'create' => $summary['create'],
                'update' => $summary['update'],
                'skip' => $summary['skip'],
                'fail' => $summary['fail'],
            ]);
        }, 3);
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

    private function safeReviewValue(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            return mb_strlen($value, 'UTF-8') > 500
                ? mb_substr($value, 0, 500, 'UTF-8').'…'
                : $value;
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        try {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '[value unavailable]';
        }

        return mb_strlen($encoded, 'UTF-8') > 500
            ? mb_substr($encoded, 0, 500, 'UTF-8').'…'
            : $encoded;
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
