<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\ImportStatus;
use SmartImport\Jobs\DeliverImportOutboxJob;
use SmartImport\Jobs\ExecuteImportChunkJob;
use SmartImport\Jobs\GenerateImportPreviewJob;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Models\ImportOutboxEvent;
use SmartImport\Models\ImportSession;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportPreviewService;
use SmartImport\Services\ImportNotificationService;
use SmartImport\Services\ImportOutboxDeliveryService;
use SmartImport\Services\ImportStagingService;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\Fixtures\QueueTestActor;
use SmartImport\Tests\Fixtures\StagingImportDefinition;
use SmartImport\Tests\TestCase;

final class RealQueueWorkflowTest extends TestCase
{
    use DatabaseMigrations;

    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Schema::dropIfExists('smart_import_test_actors');
        Schema::create('smart_import_test_actors', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $this->queueName = 'smart-import-test-'.bin2hex(random_bytes(4));

        foreach (['create', 'view', 'update', 'queue', 'useImporter'] as $ability) {
            Gate::define($ability, static fn (): bool => true);
        }

        config()->set('smart-import.importers', [StagingImportDefinition::class]);
        config()->set('smart-import.queue.connection', 'database');
        config()->set('smart-import.queue.name', $this->queueName);
        config()->set('smart-import.preview.async_threshold_rows', 1);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.after_commit', false);

        Schema::create('import_target_models', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('api_token')->nullable();
            $table->string('department')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    #[Test]
    public function actual_database_queue_workers_complete_staging_queued_preview_execution_and_notification_delivery(): void
    {
        $actor = QueueTestActor::query()->create(['name' => 'Import operator']);
        $otherActor = QueueTestActor::query()->create(['name' => 'Other operator']);
        $session = app(FileIntakeService::class)->upload(
            UploadedFile::fake()->createWithContent(
                'queue-contacts.csv',
                "Name,Email\nAda,ada@example.test\nGrace,grace@example.test\n",
            ),
            $actor,
        );

        $staging = app(ImportStagingService::class);
        $queued = $staging->start(
            (string) $session->getKey(),
            $actor,
            'staging-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email'],
        );

        $stageJobs = $this->drainQueue();
        $this->assertContains(StageImportJob::class, $stageJobs);
        $this->assertSame(ImportStatus::ReviewReady, $queued->fresh()->status);
        $this->assertSame(2, $queued->fresh()->stage_processed);
        $this->assertSame(0, ImportTargetModel::query()->count(), 'Staging must not write target records.');

        $preview = app(ImportPreviewService::class);
        $previewQueueResult = $preview->generateOrQueue(
            (string) $queued->getKey(),
            $actor,
            (int) $queued->fresh()->staging_revision,
        );
        $building = $queued->fresh();
        $queuedPreviewCommands = $this->queuedCommandNames();

        $this->assertTrue($previewQueueResult['queued']);
        $this->assertFalse($previewQueueResult['already_queued']);
        $this->assertSame('building', $building->options['preview_state']);
        $this->assertContains(GenerateImportPreviewJob::class, $queuedPreviewCommands);
        $this->assertSame(0, ImportTargetModel::query()->count(), 'Queued preview generation must not write targets.');

        $previewJobs = $this->drainQueue();
        $this->assertContains(GenerateImportPreviewJob::class, $previewJobs);
        $this->assertSame('ready', $queued->fresh()->options['preview_state']);
        $this->assertSame(0, ImportTargetModel::query()->count(), 'Preview generation must not write targets.');

        $confirmed = $preview->confirm(
            (string) $queued->getKey(),
            $actor,
            (int) $queued->fresh()->staging_revision,
        );
        $this->assertSame(ImportStatus::Queued, $confirmed->status);
        $this->assertSame(0, ImportTargetModel::query()->count(), 'Confirmation must queue execution without writing targets.');

        $executionAndDeliveryJobs = $this->drainQueue();
        $this->assertContains(ExecuteImportChunkJob::class, $executionAndDeliveryJobs);
        $this->assertContains(DeliverImportOutboxJob::class, $executionAndDeliveryJobs);

        $completed = ImportSession::query()->findOrFail($queued->getKey());
        $this->assertSame(ImportStatus::Completed, $completed->status);
        $this->assertSame(2, $completed->processed);
        $this->assertSame(2, $completed->created_count);
        $this->assertSame(2, ImportTargetModel::query()->count());
        $outbox = ImportOutboxEvent::query()->where('import_id', $completed->getKey())->sole();
        $this->assertSame('delivered', $outbox->delivery_status);

        $notifications = app(ImportNotificationService::class)->latestFor($actor);
        $this->assertCount(1, $notifications);
        $notification = $notifications->sole();
        $this->assertSame($outbox->getKey(), $notification->outbox_id);
        $this->assertSame($actor->getMorphClass(), $notification->notifiable_type);
        $this->assertSame((string) $actor->getAuthIdentifier(), $notification->notifiable_id);
        $this->assertSame('database', $notification->channel);
        $this->assertSame('completed', $notification->data['status']);
        $this->assertSame(['processed' => 2, 'created' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 0], $notification->data['counts']);
        $this->assertStringNotContainsString('ada@example.test', json_encode($notification->data, JSON_THROW_ON_ERROR));
        $this->assertCount(0, app(ImportNotificationService::class)->latestFor($otherActor));

        $this->assertSame('skipped', app(ImportOutboxDeliveryService::class)->deliver((int) $outbox->getKey()));
        $this->assertDatabaseCount('smart_import_notifications', 1);
        $this->assertSame(2, ImportTargetModel::query()->count());
        $this->assertSame(0, DB::table('jobs')->where('queue', $this->queueName)->count());
    }

    /** @return list<class-string> */
    private function drainQueue(): array
    {
        $processedJobs = 0;
        $commands = [];

        while (DB::table('jobs')->where('queue', $this->queueName)->exists()) {
            $commands[] = $this->queuedCommandNames()[0];
            $exitCode = Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => $this->queueName,
                '--once' => true,
                '--sleep' => 0,
                '--tries' => 1,
            ]);
            $this->assertSame(0, $exitCode, Artisan::output());

            if (++$processedJobs > 20) {
                self::fail('The real queue workflow exceeded its bounded worker-job budget.');
            }
        }

        return $commands;
    }

    /** @return list<class-string> */
    private function queuedCommandNames(): array
    {
        return DB::table('jobs')
            ->where('queue', $this->queueName)
            ->orderBy('id')
            ->get(['payload'])
            ->map(static function (object $job): string {
                $payload = json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR);

                return (string) ($payload['data']['commandName'] ?? '');
            })
            ->all();
    }
}
