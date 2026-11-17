<?php

namespace SmartImport\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use SmartImport\Models\ImportSession;

interface ImportSessionScope
{
    /**
     * Restrict a session query to records the explicit actor may discover.
     *
     * Host implementations must apply tenant restrictions using trusted
     * application context; values from the request must not be used directly.
     *
     * @param  Builder<ImportSession>  $query
     * @return Builder<ImportSession>
     */
    public function apply(Builder $query, Authenticatable $actor): Builder;
}
