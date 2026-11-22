<?php

namespace SmartImport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class ImportTargetModel extends Model
{
    protected $casts = [
        'is_active' => 'boolean',
    ];
}
