<?php

namespace SmartImport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SmartImport\Services\ImportOutboxDeliveryService;

final class DeliverImportOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $outboxEventId) {}

    public function handle(ImportOutboxDeliveryService $delivery): void
    {
        $delivery->deliver($this->outboxEventId);
    }
}
