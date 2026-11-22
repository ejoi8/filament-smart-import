<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Throwable;

/** Resolve an explicit CLI actor without logging that actor into a worker or request guard. */
final class ConsoleActorResolver
{
    public function resolve(string $actorId, string $guardName): Authenticatable
    {
        if (trim($actorId) === '' || strlen($actorId) > 191 || trim($guardName) === '' || strlen($guardName) > 64) {
            throw new InvalidArgumentException('Provide a valid --actor ID and --guard name.');
        }

        try {
            $guard = Auth::guard($guardName);
            $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The selected authentication guard is not available.', 0, $exception);
        }

        if (! $provider instanceof UserProvider) {
            throw new InvalidArgumentException('The selected authentication guard cannot resolve actors from IDs.');
        }

        try {
            $actor = $provider->retrieveById($actorId);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The requested actor could not be resolved by the selected guard.', 0, $exception);
        }

        if (! $actor instanceof Authenticatable) {
            throw new InvalidArgumentException('The requested actor was not found by the selected guard.');
        }

        return $actor;
    }
}
