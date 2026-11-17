<?php

namespace SmartImport\Tests\Fixtures;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

final class StaticAuthenticatableUserProvider implements UserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return (string) $identifier === '7' ? new GenericUser(['id' => 7]) : null;
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void {}

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return $this->retrieveById($credentials['id'] ?? null);
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return true;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}
}
