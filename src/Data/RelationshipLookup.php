<?php

namespace SmartImport\Data;

use InvalidArgumentException;

final readonly class RelationshipLookup
{
    public function __construct(
        public string $relationMethod,
        public string $lookupColumn,
    ) {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $relationMethod) !== 1) {
            throw new InvalidArgumentException('Relationship lookup method names must be simple PHP identifiers.');
        }

        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $lookupColumn) !== 1) {
            throw new InvalidArgumentException('Relationship lookup columns must be simple database column names.');
        }
    }
}
