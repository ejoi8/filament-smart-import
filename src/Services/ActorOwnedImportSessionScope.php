<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use SmartImport\Contracts\ImportSessionScope;
use SmartImport\Data\ImportActorIdentity;
use SmartImport\Models\ImportSession;

final class ActorOwnedImportSessionScope implements ImportSessionScope
{
    public function apply(Builder $query, Authenticatable $actor): Builder
    {
        $identity = ImportActorIdentity::from($actor);

        return $query
            ->where('created_by_type', $identity->type)
            ->where('created_by_id', $identity->id)
            ->whereNull('tenant_id');
    }
}
