<?php

namespace SmartImport\Data;

use SmartImport\Enums\ImportWriteAction;

final readonly class ImportRowContext
{
    /**
     * @param array<string, mixed> $values Confirmed normalized values from the field allowlist.
     * @param array<string, string> $importerOptions Validated choices declared by the importer.
     */
    public function __construct(
        public string $sessionId,
        public string $importerKey,
        public string $importerVersion,
        public int $confirmedRevision,
        public int $rowId,
        public int $sourceRowNumber,
        public ImportWriteAction $action,
        public string $targetType,
        public ?string $targetId,
        public array $values,
        public int $attemptId = 1,
        public array $importerOptions = [],
    ) {}

    public function idempotencyKey(): string
    {
        return $this->sessionId.':'.$this->attemptId.':'.$this->rowId;
    }
}
