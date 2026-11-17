<?php

namespace SmartImport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SmartImport\Services\ImportStagingService;
use Throwable;

final class StageImportJob implements ShouldQueue
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
    ) {}

    public function handle(ImportStagingService $staging): void
    {
        $staging->stage($this->sessionId, $this->mappingRevision, $this->stagingRevision);
    }

    public function failed(?Throwable $exception): void
    {
        app(ImportStagingService::class)->fail(
            $this->sessionId,
            $this->mappingRevision,
            $this->stagingRevision,
        );
    }
}
