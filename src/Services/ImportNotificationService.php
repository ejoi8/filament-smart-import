<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use SmartImport\Data\ImportActorIdentity;
use SmartImport\Models\ImportNotification;

final class ImportNotificationService
{
    /** @return Collection<int, ImportNotification> */
    public function latestFor(Authenticatable $actor, int $limit = 20): Collection
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Smart Import notification page limits must be between 1 and 100.');
        }

        $identity = ImportActorIdentity::from($actor);

        return ImportNotification::query()
            ->where('notifiable_type', $identity->type)
            ->where('notifiable_id', $identity->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function markRead(int $notificationId, Authenticatable $actor): bool
    {
        $identity = ImportActorIdentity::from($actor);

        return ImportNotification::query()
            ->whereKey($notificationId)
            ->where('notifiable_type', $identity->type)
            ->where('notifiable_id', $identity->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]) > 0;
    }
}
