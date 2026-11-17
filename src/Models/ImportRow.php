<?php

namespace SmartImport\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SmartImport\Enums\ImportStatus;

class ImportRow extends Model
{
    private const FROZEN_INPUT_FIELDS = [
        'source_sheet',
        'source_row_number',
        'source_fingerprint',
        'raw_values',
        'mapped_values',
        'normalized_values',
        'validation_errors',
        'warnings',
        'validation_status',
        'staging_revision',
        'match_key_hash',
        'resolved_target_type',
        'resolved_target_id',
        'change_type',
        'preview',
    ];

    protected $table = 'smart_import_rows';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            $changedInputFields = array_intersect(array_keys($row->getDirty()), self::FROZEN_INPUT_FIELDS);

            if ($changedInputFields === []) {
                return;
            }

            $session = ImportSession::query()->find($row->import_id);

            if (in_array($session?->status, [
                ImportStatus::Queued,
                ImportStatus::Importing,
                ImportStatus::Cancelling,
                ImportStatus::Completed,
                ImportStatus::Failed,
                ImportStatus::Cancelled,
            ], true)) {
                throw new DomainException(sprintf(
                    'Cannot change frozen staged row input after import confirmation: %s.',
                    implode(', ', $changedInputFields),
                ));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'source_row_number' => 'integer',
            'staging_revision' => 'integer',
            'raw_values' => 'array',
            'mapped_values' => 'array',
            'normalized_values' => 'array',
            'validation_errors' => 'array',
            'warnings' => 'array',
            'preview' => 'array',
            'attempt_count' => 'integer',
            'execution_history' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function importSession(): BelongsTo
    {
        return $this->belongsTo(ImportSession::class, 'import_id');
    }
}
