<?php

namespace SmartImport\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ImportPreset extends Model
{
    use HasUuids;

    protected $table = 'smart_import_presets';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'options' => 'array',
        ];
    }
}
