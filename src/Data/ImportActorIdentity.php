<?php

namespace SmartImport\Data;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final readonly class ImportActorIdentity
{
    private function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function from(Authenticatable $actor): self
    {
        $identifier = $actor->getAuthIdentifier();

        if ((! is_string($identifier) && ! is_int($identifier)) || (string) $identifier === '') {
            throw new AuthorizationException('An authenticated actor with a valid identifier is required.');
        }

        return new self(
            $actor instanceof Model ? $actor->getMorphClass() : $actor::class,
            (string) $identifier,
        );
    }
}
