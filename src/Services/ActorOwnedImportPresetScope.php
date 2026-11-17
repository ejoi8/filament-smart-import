<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use SmartImport\Contracts\ImportPresetScope;
use SmartImport\Data\ImportActorIdentity;

final class ActorOwnedImportPresetScope implements ImportPresetScope
{
    public function keyFor(Authenticatable $actor): string
    {
        $identity = ImportActorIdentity::from($actor);

        return 'actor:'.hash('sha256', $identity->type."\0".$identity->id);
    }

    public function apply(Builder $query, Authenticatable $actor): Builder
    {
        return $query->where('scope_key', $this->keyFor($actor));
    }
}
