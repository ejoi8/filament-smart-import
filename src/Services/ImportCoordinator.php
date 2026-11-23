<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use DomainException;
use InvalidArgumentException;
use SmartImport\Data\ImportActorIdentity;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Models\ImportSession;

final class ImportCoordinator
{
    private const CREATE_FIELDS = [
        'source',
        'original_filename',
        'storage_disk',
        'storage_path',
        'claimed_storage_path',
        'file_hash',
        'possible_duplicate_of',
        'file_size',
        'file_type',
        'importer_key',
        'tenant_id',
    ];

    public function __construct(
        private ImportAuthorization $authorization,
        private ImporterRegistry $importers,
        private ImportAuditService $audit,
    ) {}

    /**
     * Register a validated source file as an unstarted import session.
     *
     * @param  array<string, mixed>  $attributes
     *
     * $attributes and tenant IDs must come from trusted server-side intake code,
     * never directly from request fields.
     */
    public function create(array $attributes, Authenticatable $actor): ImportSession
    {
        return $this->createValidatedSession($attributes, $actor, false);
    }

    /** Register an unparseable or unsafe source as a rejected, unassigned intake record. */
    public function createRejected(array $attributes, string $reason, Authenticatable $actor): ImportSession
    {
        $reason = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $reason) ?? '');

        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('A sanitized rejection reason of at most 1000 characters is required.');
        }

        if (($attributes['source'] ?? null) !== 'sftp' || ($attributes['importer_key'] ?? null) !== null) {
            throw new InvalidArgumentException('Quarantined intake records must be unassigned SFTP sources.');
        }

        return $this->createValidatedSession($attributes, $actor, true, $reason);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createValidatedSession(
        array $attributes,
        Authenticatable $actor,
        bool $allowMissingChecksum,
        ?string $rejectionReason = null,
    ): ImportSession {
        $unknownFields = array_diff(array_keys($attributes), self::CREATE_FIELDS);

        if ($unknownFields !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported import session creation field(s): %s.',
                implode(', ', $unknownFields),
            ));
        }

        $data = Validator::make($attributes, [
            'source' => ['required', 'string', 'in:web,sftp,cli', 'max:16'],
            'original_filename' => ['required', 'string', 'max:255'],
            'storage_disk' => ['required', 'string', 'max:64'],
            'storage_path' => ['required', 'string', 'max:512'],
            'claimed_storage_path' => ['nullable', 'string', 'max:512'],
            'file_hash' => [$allowMissingChecksum ? 'nullable' : 'required', 'string', 'regex:/\A[a-f0-9]{64}\z/i'],
            'possible_duplicate_of' => ['nullable', 'uuid', 'exists:smart_imports,id'],
            'file_size' => ['required', 'integer', 'min:0'],
            'file_type' => ['required', 'string', 'max:100'],
            'importer_key' => ['nullable', 'string', 'min:1', 'max:100'],
            'tenant_id' => ['nullable', 'string', 'min:1', 'max:191'],
        ])->validate();

        if (! $allowMissingChecksum && ! is_string($data['file_hash'] ?? null)) {
            throw new InvalidArgumentException('A validated import source must have a SHA-256 checksum.');
        }

        if (is_string($data['file_hash'] ?? null)) {
            $data['file_hash'] = strtolower($data['file_hash']);
        }

        if ($allowMissingChecksum && ($data['importer_key'] ?? null) !== null) {
            throw new InvalidArgumentException('A quarantined source cannot be assigned to an importer.');
        }

        $identity = ImportActorIdentity::from($actor);
        $this->authorizeIntakeCreation($actor, $data['tenant_id'] ?? null);

        if (($data['importer_key'] ?? null) !== null) {
            $definition = $this->importers->get($data['importer_key']);
            $this->authorization->authorize('useImporter', $actor, $definition);
            $data['importer_version'] = $definition->version();
        }

        return DB::transaction(function () use ($data, $identity, $actor, $rejectionReason): ImportSession {
            $session = new ImportSession();
            $session->forceFill(array_merge($data, [
                'created_by_type' => $identity->type,
                'created_by_id' => $identity->id,
                'intake_status' => IntakeStatus::Discovered,
                'status' => null,
            ]));
            $session->save();

            if ($rejectionReason !== null) {
                $session->transitionIntakeTo(IntakeStatus::Rejected);
                $session->error_summary = $rejectionReason;
                $session->save();
            }

            $this->audit->record(
                $session,
                $rejectionReason === null ? 'intake.registered' : 'intake.rejected',
                $actor,
                $rejectionReason === null ? 'source_registered' : 'unsafe_source',
                ['source' => $session->source, 'file_type' => $session->file_type],
            );

            return $session;
        });
    }

    /** Check intake creation permissions before a caller reads or claims a source file. */
    public function authorizeIntakeCreation(Authenticatable $actor, ?string $tenantId = null): void
    {
        $this->authorization->authorize('create', $actor, ImportSession::class);

        if ($tenantId !== null) {
            $this->authorization->authorize('createForTenant', $actor, $tenantId);
        }
    }

    /**
     * Change intake status and server-owned file metadata while holding the session row lock.
     * The callback performs any matching filesystem move before the metadata is committed.
     *
     * @param  array{claimed_storage_path?: string, error_summary?: string|null}  $metadata
     * @param  callable(ImportSession): void  $beforeCommit
     */
    public function transitionIntakeWithMetadata(
        string $sessionId,
        IntakeStatus $next,
        Authenticatable $actor,
        array $metadata,
        callable $beforeCommit,
    ): ImportSession {
        $unsupported = array_diff(array_keys($metadata), ['claimed_storage_path', 'error_summary']);

        if ($unsupported !== []) {
            throw new InvalidArgumentException('Unsupported intake metadata field(s).');
        }

        if (isset($metadata['claimed_storage_path'])
            && (! is_string($metadata['claimed_storage_path']) || strlen($metadata['claimed_storage_path']) > 512)) {
            throw new InvalidArgumentException('The claimed file path is invalid.');
        }

        if (isset($metadata['error_summary'])
            && (! is_string($metadata['error_summary']) || mb_strlen($metadata['error_summary'], 'UTF-8') > 1000)) {
            throw new InvalidArgumentException('The intake message is invalid.');
        }

        return DB::transaction(function () use ($sessionId, $next, $actor, $metadata, $beforeCommit): ImportSession {
            $session = $this->authorization
                ->applyActorScope(ImportSession::query()->lockForUpdate(), $actor)
                ->whereKey($sessionId)
                ->firstOrFail();

            $this->authorization->authorize('update', $actor, $session);

            if ($session->intake_status === $next) {
                throw new DomainException('The intake file already has the requested status.');
            }

            $beforeCommit($session);
            $session->transitionIntakeTo($next);
            $session->forceFill($metadata);
            $session->save();

            $event = match ($next) {
                IntakeStatus::Rejected => 'intake.rejected',
                IntakeStatus::Archived => 'intake.archived',
                IntakeStatus::Claimed => 'intake.claimed',
                IntakeStatus::Ready, IntakeStatus::Discovered => null,
            };

            if ($event !== null) {
                $this->audit->record(
                    $session,
                    $event,
                    $actor,
                    $next === IntakeStatus::Rejected ? 'operator_rejected' : 'operator_action',
                    $event === 'intake.rejected' ? ['source' => $session->source] : [],
                );
            }

            return $session;
        }, 3);
    }

    /**
     * Get a session only after applying actor scope and its view policy.
     *
     * @throws ModelNotFoundException<ImportSession>
     */
    public function get(string $sessionId, Authenticatable $actor): ImportSession
    {
        $session = $this->authorization
            ->applyActorScope(ImportSession::query(), $actor)
            ->whereKey($sessionId)
            ->firstOrFail();

        $this->authorization->authorize('view', $actor, $session);

        return $session;
    }

    /** Assign or reassign a registered importer before row staging has started. */
    public function assignImporter(string $sessionId, string $importerKey, Authenticatable $actor): ImportSession
    {
        return DB::transaction(function () use ($sessionId, $importerKey, $actor): ImportSession {
            $session = $this->authorization
                ->applyActorScope(ImportSession::query()->lockForUpdate(), $actor)
                ->whereKey($sessionId)
                ->firstOrFail();

            $this->authorization->authorize('update', $actor, $session);

            $definition = $this->importers->get($importerKey);
            $this->authorization->authorize('useImporter', $actor, $definition);

            if ($session->status !== null) {
                throw new DomainException('An importer cannot be assigned after staging has started.');
            }

            $this->assertImportableIntake($session);

            $session->forceFill([
                'importer_key' => $definition->key(),
                'importer_version' => $definition->version(),
                'target_model' => $definition->targetModel(),
            ]);

            if ($session->intake_status !== IntakeStatus::Ready) {
                $session->transitionIntakeTo(IntakeStatus::Ready);
            }

            $session->save();
            $this->audit->record($session, 'importer.assigned', $actor, 'operator_assigned', [
                'importer_key' => $definition->key(),
            ]);

            return $session;
        });
    }

    /**
     * Persist the approved importer, sheet, and complete column mapping before staging starts.
     *
     * @param array<int, string|null> $mapping One-based source columns to allowed fields or explicit null ignores.
     */
    public function beginStaging(
        string $sessionId,
        string $importerKey,
        int $sheetIndex,
        string $sheetName,
        ?int $headerRow,
        array $mapping,
        int $estimatedTotal,
        Authenticatable $actor,
        array $options = [],
    ): ImportSession {
        if ($sheetIndex < 0 || $sheetIndex > 65535) {
            throw new InvalidArgumentException('The selected worksheet index must be between 0 and 65535.');
        }

        if ($sheetName === '' || trim($sheetName) !== $sheetName || ! mb_check_encoding($sheetName, 'UTF-8') || mb_strlen($sheetName, 'UTF-8') > 31) {
            throw new InvalidArgumentException('The selected worksheet name must be valid UTF-8 and at most 31 characters.');
        }

        if ($headerRow !== null && $headerRow < 1) {
            throw new InvalidArgumentException('The selected header row must be positive and one-based.');
        }

        if ($estimatedTotal < 0) {
            throw new InvalidArgumentException('The estimated staging row count cannot be negative.');
        }

        if (array_diff(array_keys($options), ['reader', 'importer', 'security']) !== []
            || ! is_array($options['reader'] ?? null)
            || ! is_array($options['importer'] ?? null)
            || ! is_array($options['security'] ?? null)
            || array_diff(array_keys($options['security']), ['index_hash_version']) !== []
            || ! in_array($options['security']['index_hash_version'] ?? null, [1, 2], true)) {
            throw new InvalidArgumentException('Import configuration options must use the validated reader/importer structure.');
        }

        $definition = $this->importers->get($importerKey);
        $this->authorization->authorize('useImporter', $actor, $definition);
        $allowedFields = [];

        foreach ($definition->fields() as $field) {
            $allowedFields[$field->name] = $field;
        }

        $usedFields = [];

        foreach ($mapping as $sourceColumn => $fieldName) {
            if (! is_int($sourceColumn) || $sourceColumn < 1) {
                throw new InvalidArgumentException('Mappings must use positive, one-based source column indexes.');
            }

            if ($fieldName === null) {
                continue;
            }

            if (! is_string($fieldName) || ! array_key_exists($fieldName, $allowedFields)) {
                throw new InvalidArgumentException(sprintf('Mapping for source column [%d] selects a field not allowed by the importer.', $sourceColumn));
            }

            if (array_key_exists($fieldName, $usedFields)) {
                throw new InvalidArgumentException(sprintf('Importer field [%s] is mapped from more than one source column.', $fieldName));
            }

            $usedFields[$fieldName] = true;
        }

        foreach ($allowedFields as $fieldName => $field) {
            if ($field->required && ! array_key_exists($fieldName, $usedFields)) {
                throw new InvalidArgumentException(sprintf('Required importer field [%s] must be mapped before staging.', $fieldName));
            }
        }

        ksort($mapping, SORT_NUMERIC);

        return DB::transaction(function () use (
            $sessionId,
            $importerKey,
            $definition,
            $sheetIndex,
            $sheetName,
            $headerRow,
            $mapping,
            $estimatedTotal,
            $actor,
            $options,
        ): ImportSession {
            $session = $this->authorization
                ->applyActorScope(ImportSession::query()->lockForUpdate(), $actor)
                ->whereKey($sessionId)
                ->firstOrFail();

            $this->authorization->authorize('update', $actor, $session);

            $this->assertImportableIntake($session);

            if (! in_array($session->status, [null, ImportStatus::Uploaded, ImportStatus::ReviewReady], true)) {
                throw new DomainException('Only an unstaged or review-ready import can start staging.');
            }

            if ($session->status === ImportStatus::ReviewReady && $session->importer_key !== $importerKey) {
                throw new DomainException('The importer cannot be changed after rows have been staged.');
            }

            $currentMapping = $session->mapping ?? [];
            ksort($currentMapping, SORT_NUMERIC);
            $currentOptions = $session->options ?? [];
            $currentImportOptions = [
                'reader' => $currentOptions['reader'] ?? [],
                'importer' => $currentOptions['importer'] ?? [],
                'security' => $currentOptions['security'] ?? ['index_hash_version' => 1],
            ];
            $mappingChanged = $session->importer_key !== $importerKey
                || $session->importer_version !== $definition->version()
                || $session->sheet_index !== $sheetIndex
                || $session->sheet_name !== $sheetName
                || $session->sheet_header_row !== $headerRow
                || $currentMapping !== $mapping
                || $currentImportOptions !== $options;

            $session->rows()->delete();
            $session->forceFill([
                'importer_key' => $definition->key(),
                'importer_version' => $definition->version(),
                'target_model' => $definition->targetModel(),
                'sheet_index' => $sheetIndex,
                'sheet_name' => $sheetName,
                'sheet_header_row' => $headerRow,
                'mapping' => $mapping,
                'options' => $options,
                'mapping_revision' => $session->mapping_revision + ($mappingChanged ? 1 : 0),
                'staging_revision' => $session->staging_revision + 1,
                'stage_total' => $estimatedTotal,
                'stage_processed' => 0,
                'stage_valid' => 0,
                'stage_invalid' => 0,
                'stage_warning' => 0,
                'recovery_count' => 0,
                'error_summary' => null,
                'heartbeat_at' => now(),
            ]);

            if ($session->status === null) {
                $session->transitionTo(ImportStatus::Uploaded);
            }

            $session->transitionTo(ImportStatus::Staging);
            $session->save();
            $this->audit->record($session, 'configuration.saved', $actor, 'review_configuration_saved', array_filter([
                'importer_key' => $definition->key(),
                'mapping_revision' => $session->mapping_revision,
                'staging_revision' => $session->staging_revision,
                'sheet_index' => $sheetIndex,
                'header_row' => $headerRow,
                'source_column_count' => count($mapping),
                'mapped_field_count' => count(array_filter($mapping, static fn (?string $field): bool => $field !== null)),
                'settings_changed' => $mappingChanged,
            ], static fn (mixed $value): bool => $value !== null));

            return $session;
        });
    }

    /**
     * Apply one state transition under a row lock.
     *
     * Returns true only when this call changed and persisted the state. A false
     * result means the requested state was already current.
     */
    /** Reject any import action for sources that were rejected or archived. */
    public function assertImportableIntake(ImportSession $session): void
    {
        if ($session->intake_status === IntakeStatus::Archived) {
            throw new DomainException('This file is archived and cannot be imported. Create a new intake to import it again.');
        }

        if ($session->intake_status === IntakeStatus::Rejected) {
            throw new DomainException('This file was rejected and cannot be imported. Create a new intake to import it again.');
        }
    }

    public function transition(
        string $sessionId,
        ImportStatus|IntakeStatus $next,
        Authenticatable $actor,
    ): bool {
        return DB::transaction(function () use ($sessionId, $next, $actor): bool {
            $session = $this->authorization
                ->applyActorScope(ImportSession::query()->lockForUpdate(), $actor)
                ->whereKey($sessionId)
                ->firstOrFail();

            $this->authorization->authorize('update', $actor, $session);

            if ($next instanceof ImportStatus
                && in_array($next, [ImportStatus::Staging, ImportStatus::Queued, ImportStatus::Importing], true)) {
                $this->assertImportableIntake($session);
            }

            $current = $next instanceof ImportStatus ? $session->status : $session->intake_status;

            if ($current === $next) {
                return false;
            }

            if ($next instanceof ImportStatus) {
                $session->transitionTo($next);
            } else {
                $session->transitionIntakeTo($next);
            }

            $session->save();

            return true;
        });
    }
}
