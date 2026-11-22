<?php

namespace SmartImport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportNotification extends Model
{
    protected $table = 'smart_import_notifications';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(ImportOutboxEvent::class, 'outbox_id');
    }
}
