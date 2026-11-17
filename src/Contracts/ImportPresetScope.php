<?php

namespace SmartImport\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

interface ImportPresetScope
{
    /** Return a stable, opaque key for the actor's current preset sharing scope. */
    public function keyFor(Authenticatable $actor): string;

    /** Restrict preset queries to the same trusted scope returned by keyFor(). */
    public function apply(Builder $query, Authenticatable $actor): Builder;
}
