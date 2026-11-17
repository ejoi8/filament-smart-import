<?php

namespace SmartImport\Events;

use SmartImport\Enums\ImportStatus;

final readonly class ImportFinished
{
    public function __construct(
        public string $sessionId,
        public int $attemptId,
        public ImportStatus $status,
        public int $processed,
        public int $created,
        public int $updated,
        public int $skipped,
        public int $failed,
    ) {}
}
