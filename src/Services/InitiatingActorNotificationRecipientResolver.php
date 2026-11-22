<?php

namespace SmartImport\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Contracts\Auth\Authenticatable;
use SmartImport\Contracts\ImportNotificationRecipientResolver;
use SmartImport\Data\ImportNotificationRecipient;
use SmartImport\Models\ImportSession;

/** Notify only the initiating actor, and only while its Eloquent auth record still exists. */
final class InitiatingActorNotificationRecipientResolver implements ImportNotificationRecipientResolver
{
    public function recipients(ImportSession $session): array
    {
        if (! is_string($session->created_by_type) || ! is_string($session->created_by_id)) {
            return [];
        }

        $class = Relation::getMorphedModel($session->created_by_type) ?? $session->created_by_type;

        if (! class_exists($class) || ! is_a($class, Model::class, true) || ! is_a($class, Authenticatable::class, true)) {
            return [];
        }

        $actor = app($class);

        if (! $actor instanceof Model || ! $actor->newQuery()->whereKey($session->created_by_id)->exists()) {
            return [];
        }

        return [new ImportNotificationRecipient($session->created_by_type, $session->created_by_id)];
    }
}
