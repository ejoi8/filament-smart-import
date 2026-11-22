<?php

namespace SmartImport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportOutboxEvent extends Model
{
    protected $table = 'smart_import_outbox';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'attempt_id' => 'integer',
            'recovery_count' => 'integer',
            'payload_version' => 'integer',
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function importSession(): BelongsTo
    {
        return $this->belongsTo(ImportSession::class, 'import_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(ImportNotification::class, 'outbox_id');
    }
}
