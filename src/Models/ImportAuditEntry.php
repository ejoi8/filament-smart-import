<?php

namespace SmartImport\Models;

use Illuminate\Database\Eloquent\Model;

class ImportAuditEntry extends Model
{
    protected $table = 'smart_import_audit_entries';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'mapping_revision' => 'integer',
            'staging_revision' => 'integer',
            'attempt_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
