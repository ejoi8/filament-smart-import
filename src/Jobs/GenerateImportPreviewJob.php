<?php

namespace SmartImport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SmartImport\Services\ImportPreviewService;
use Throwable;

final class GenerateImportPreviewJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $sessionId,
        public int $mappingRevision,
        public int $stagingRevision,
        public string $generationId,
    ) {}

    public function handle(ImportPreviewService $previews): void
    {
        $previews->generateQueued(
            $this->sessionId,
            $this->mappingRevision,
            $this->stagingRevision,
            $this->generationId,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(ImportPreviewService::class)->failQueuedPreview(
            $this->sessionId,
            $this->mappingRevision,
            $this->stagingRevision,
            $this->generationId,
        );
    }
}
