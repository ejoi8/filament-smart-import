<?php

namespace SmartImport\Contracts;

interface FieldTransformer
{
    /**
     * Return a transformed field value.
     *
     * Implementations are application-owned code and must be deterministic and side-effect free.
     */
    public function transform(mixed $value): mixed;
}
