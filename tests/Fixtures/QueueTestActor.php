<?php

namespace SmartImport\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class QueueTestActor extends Authenticatable
{
    protected $table = 'smart_import_test_actors';

    protected $guarded = [];

    public $timestamps = false;
}
