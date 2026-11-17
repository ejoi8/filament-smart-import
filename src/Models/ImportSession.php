<?php

namespace SmartImport\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\IntakeStatus;

class ImportSession extends Model
{
    use HasUuids;

    private const IMMUTABLE_FILE_IDENTITY_FIELDS = [
        'source',
        'original_filename',
        'storage_disk',
        'storage_path',
        'file_hash',
        'file_size',
        'file_type',
    ];

    private const FROZEN_EXECUTION_FIELDS = [
        'importer_key',
        'importer_version',
        'target_model',
        'sheet_name',
        'sheet_index',
        'sheet_header_row',
        'mapping',
        'mapping_revision',
        'staging_revision',
        'confirmed_revision',
    ];

    protected $table = 'smart_imports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $session): void {
            $originalStatus = $session->getRawOriginal('status');

            if (in_array($originalStatus, [
                ImportStatus::Queued->value,
                ImportStatus::Importing->value,
                ImportStatus::Cancelling->value,
                ImportStatus::Completed->value,
                ImportStatus::Failed->value,
                ImportStatus::Cancelled->value,
            ], true)) {
                $changedExecutionFields = array_intersect(
                    array_keys($session->getDirty()),
                    self::FROZEN_EXECUTION_FIELDS,
                );

                if ($changedExecutionFields !== []) {
                    throw new DomainException(sprintf(
                        'Cannot change frozen import execution field(s) after confirmation: %s.',
                        implode(', ', $changedExecutionFields),
                    ));
                }
            }

            $changedIdentityFields = array_intersect(
                array_keys($session->getDirty()),
                self::IMMUTABLE_FILE_IDENTITY_FIELDS,
            );

            if ($changedIdentityFields !== []) {
                throw new DomainException(sprintf(
                    'Cannot change immutable source file identity field(s): %s.',
                    implode(', ', $changedIdentityFields),
                ));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'intake_status' => IntakeStatus::class,
            'status' => ImportStatus::class,
            'mapping' => 'array',
            'options' => 'array',
            'attempt_history' => 'array',
            'file_size' => 'integer',
            'sheet_index' => 'integer',
            'sheet_header_row' => 'integer',
            'mapping_revision' => 'integer',
            'staging_revision' => 'integer',
            'confirmed_revision' => 'integer',
            'current_attempt' => 'integer',
            'recovery_count' => 'integer',
            'stage_total' => 'integer',
            'stage_processed' => 'integer',
            'stage_valid' => 'integer',
            'stage_invalid' => 'integer',
            'stage_warning' => 'integer',
            'execution_total' => 'integer',
            'processed' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'cancel_requested_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'import_id');
    }

    public function possibleDuplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'possible_duplicate_of');
    }

    public function possibleDuplicates(): HasMany
    {
        return $this->hasMany(self::class, 'possible_duplicate_of');
    }

    public function transitionTo(ImportStatus $next): void
    {
        $current = $this->status;

        if ($current === $next) {
            return;
        }

        if ($current === null ? $next !== ImportStatus::Uploaded : ! $current->canTransitionTo($next)) {
            throw new DomainException(sprintf(
                'Cannot transition import status from [%s] to [%s].',
                $current?->value ?? 'unassigned',
                $next->value,
            ));
        }

        $this->status = $next;
    }

    public function transitionIntakeTo(IntakeStatus $next): void
    {
        $current = $this->intake_status;

        if ($current === $next) {
            return;
        }

        if ($current === null ? $next !== IntakeStatus::Discovered : ! $current->canTransitionTo($next)) {
            throw new DomainException(sprintf(
                'Cannot transition intake status from [%s] to [%s].',
                $current?->value ?? 'unregistered',
                $next->value,
            ));
        }

        $this->intake_status = $next;
    }
}
