<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Contracts\ImportNotificationRecipientResolver;
use SmartImport\Data\ImportNotificationRecipient;
use SmartImport\Enums\ImportStatus;
use SmartImport\Events\ImportOutboxAvailable;
use SmartImport\Jobs\DeliverImportOutboxJob;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Models\ImportAuditEntry;
use SmartImport\Models\ImportOutboxEvent;
use SmartImport\Models\ImportSession;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportExecutionLifecycle;
use SmartImport\Services\ImportExecutionService;
use SmartImport\Services\ImportNotificationService;
use SmartImport\Services\ImportOutboxDeliveryService;
use SmartImport\Services\ImportPreviewService;
use SmartImport\Services\ImportRecoveryService;
use SmartImport\Services\ImportRetryService;
use SmartImport\Services\ImportStagingService;
use SmartImport\Tests\Fixtures\PreviewImportDefinition;
use SmartImport\Tests\TestCase;

class ImportOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        Schema::create('import_target_models', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('api_token')->nullable();
            $table->string('department')->nullable();
            $table->timestamps();
        });
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('smart-import.importers', [PreviewImportDefinition::class]);
        config()->set('smart-import.recovery.stale_after_seconds', 30);
        config()->set('smart-import.recovery.max_recoveries', 1);
        config()->set('smart-import.notifications.backoff_seconds', [0]);

        foreach (['create', 'view', 'update', 'queue', 'cancel', 'retry', 'useImporter'] as $ability) {
            Gate::define($ability, static fn (): bool => true);
        }

        $this->useRecipientResolver();
    }

    #[Test]
    public function terminal_outbox_delivery_is_durable_idempotent_and_actor_scoped(): void
    {
        Event::fake([ImportOutboxAvailable::class]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirmedSession($actor, "Ada,ada@example.test,secret,Sales\n");

        $cancelled = app(ImportExecutionLifecycle::class)->requestCancellation((string) $session->getKey(), $actor);
        $outbox = ImportOutboxEvent::query()->where('import_id', $session->getKey())->firstOrFail();

        $this->assertSame(ImportStatus::Cancelled, $cancelled->status);
        $this->assertSame('pending', $outbox->delivery_status);
        Queue::assertPushed(DeliverImportOutboxJob::class);

        $delivery = app(ImportOutboxDeliveryService::class);
        $this->assertSame('delivered', $delivery->deliver((int) $outbox->getKey()));
        $this->assertSame('skipped', $delivery->deliver((int) $outbox->getKey()));
        $this->assertSame('delivered', $outbox->fresh()->delivery_status);
        $this->assertDatabaseCount('smart_import_notifications', 1);

        $notification = app(ImportNotificationService::class)->latestFor($actor)->sole();
        $otherActor = new GenericUser(['id' => 8]);
        $this->assertCount(0, app(ImportNotificationService::class)->latestFor($otherActor));
        $this->assertTrue(app(ImportNotificationService::class)->markRead((int) $notification->getKey(), $actor));
        $this->assertFalse(app(ImportNotificationService::class)->markRead((int) $notification->getKey(), $otherActor));
        $this->assertStringNotContainsString('secret', json_encode($notification->data, JSON_THROW_ON_ERROR));
        Event::assertDispatched(ImportOutboxAvailable::class, fn (ImportOutboxAvailable $event): bool =>
            $event->eventKey === $outbox->event_key && ! str_contains(json_encode($event->payload), 'secret'));
    }

    #[Test]
    public function redelivery_after_notification_insert_does_not_duplicate_the_internal_record(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirmedSession($actor, "Ada,ada@example.test,secret,Sales\n");
        app(ImportExecutionLifecycle::class)->requestCancellation((string) $session->getKey(), $actor);
        $outbox = ImportOutboxEvent::query()->where('import_id', $session->getKey())->firstOrFail();

        Event::listen(ImportOutboxAvailable::class, static function (): void {
            throw new \RuntimeException('test delivery interruption');
        });

        $delivery = app(ImportOutboxDeliveryService::class);
        $this->assertSame('failed', $delivery->deliver((int) $outbox->getKey()));
        $this->assertSame('pending', $outbox->fresh()->delivery_status);
        $this->assertDatabaseCount('smart_import_notifications', 1);
        $failureAudit = ImportAuditEntry::query()
            ->where('import_id', $session->getKey())
            ->where('event', 'notification.delivery_failed')
            ->sole();
        $this->assertSame(['outbox_id' => (int) $outbox->getKey(), 'delivery_attempt' => 1], $failureAudit->metadata);

        app('events')->forget(ImportOutboxAvailable::class);
        $this->assertSame('delivered', $delivery->deliver((int) $outbox->getKey()));
        $this->assertDatabaseCount('smart_import_notifications', 1);
        $this->assertSame('delivered', $outbox->fresh()->delivery_status);
    }

    #[Test]
    public function recovery_supersedes_provisional_failure_and_releases_only_the_final_failure(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirmedSession($actor, "Ada,ada@example.test,secret,Sales\n");
        $lifecycle = app(ImportExecutionLifecycle::class);
        $this->assertTrue($lifecycle->beginChunk((string) $session->getKey(), 1, $session->confirmed_revision));
        $lifecycle->failChunk((string) $session->getKey(), 1, $session->confirmed_revision);
        $provisional = ImportOutboxEvent::query()->where('import_id', $session->getKey())->firstOrFail();

        $this->assertSame(ImportStatus::Failed, $session->fresh()->status);
        $this->assertSame('deferred', $provisional->delivery_status);

        ImportSession::query()->whereKey($session->getKey())->update(['heartbeat_at' => now()->subHour()]);
        $recovered = app(ImportRecoveryService::class)->recoverStale();

        $this->assertSame(1, $recovered['execution_requeued']);
        $this->assertSame('superseded', $provisional->fresh()->delivery_status);
        $this->assertSame(1, $session->fresh()->recovery_count);

        $this->assertTrue($lifecycle->beginChunk((string) $session->getKey(), 1, $session->confirmed_revision));
        $lifecycle->failChunk((string) $session->getKey(), 1, $session->confirmed_revision);
        $finalFailure = ImportOutboxEvent::query()->where('import_id', $session->getKey())->where('recovery_count', 1)->firstOrFail();
        ImportSession::query()->whereKey($session->getKey())->update(['heartbeat_at' => now()->subHour()]);

        $settled = app(ImportRecoveryService::class)->recoverStale();

        $this->assertSame(1, $settled['failed']);
        $this->assertSame('pending', $finalFailure->fresh()->delivery_status);
        $this->assertSame('The configured safe recovery limit was reached. Check queue worker health before manually retrying this import.', $session->fresh()->error_summary);

        $secondScan = app(ImportRecoveryService::class)->recoverStale();
        $this->assertSame(0, $secondScan['scanned']);
    }

    #[Test]
    public function recovery_reconciles_a_failed_session_after_all_row_outcomes_committed(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirmedSession($actor, "Ada,ada@example.test,secret,Sales\n");
        $row = $session->rows()->firstOrFail();
        $row->forceFill(['execution_status' => 'created'])->save();
        $session->forceFill([
            'status' => ImportStatus::Failed,
            'processed' => 1,
            'created_count' => 1,
            'heartbeat_at' => now()->subHour(),
        ])->save();

        $counts = app(ImportRecoveryService::class)->recoverStale();

        $this->assertSame(1, $counts['completed']);
        $this->assertSame(ImportStatus::Completed, $session->fresh()->status);
        $this->assertSame('pending', ImportOutboxEvent::query()->where('import_id', $session->getKey())->firstOrFail()->delivery_status);
    }

    #[Test]
    public function a_new_failed_row_retry_attempt_has_a_distinct_terminal_event_identity(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirmedSession($actor, "Ada,ada@example.test,secret,Sales\n");
        $row = $session->rows()->firstOrFail();
        $lifecycle = app(ImportExecutionLifecycle::class);

        $this->assertTrue($lifecycle->beginChunk((string) $session->getKey(), 1, $session->confirmed_revision));
        DB::table('import_target_models')->insert([
            'name' => 'Concurrent contact',
            'email' => 'ada@example.test',
            'api_token' => 'other',
            'department' => 'Sales',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $failed = app(ImportExecutionService::class)->processRow(
            (string) $session->getKey(),
            (int) $row->getKey(),
            (int) $session->confirmed_revision,
            1,
        );
        $this->assertSame('failed', $failed['execution_status']);
        $this->assertTrue($lifecycle->completeIfReady((string) $session->getKey(), 1, $session->confirmed_revision));
        $firstEvent = ImportOutboxEvent::query()->where('import_id', $session->getKey())->firstOrFail();

        DB::table('import_target_models')->where('email', 'ada@example.test')->delete();
        $retried = app(ImportRetryService::class)->retryFailedRows((string) $session->getKey(), $actor);
        $this->assertSame(2, $retried->current_attempt);
        $this->assertTrue($lifecycle->beginChunk((string) $session->getKey(), 2, $retried->confirmed_revision));
        $created = app(ImportExecutionService::class)->processRow(
            (string) $session->getKey(),
            (int) $row->getKey(),
            (int) $retried->confirmed_revision,
            2,
        );
        $this->assertSame('created', $created['execution_status']);
        $this->assertTrue($lifecycle->completeIfReady((string) $session->getKey(), 2, $retried->confirmed_revision));
        $secondEvent = ImportOutboxEvent::query()->where('import_id', $session->getKey())->where('attempt_id', 2)->firstOrFail();

        $this->assertSame(1, $firstEvent->attempt_id);
        $this->assertNotSame($firstEvent->event_key, $secondEvent->event_key);
        $this->assertSame('pending', $firstEvent->delivery_status);
        $this->assertSame('pending', $secondEvent->delivery_status);
    }

    #[Test]
    public function relay_command_finds_durable_pending_events_and_retries_failed_delivery_only(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirmedSession($actor, "Ada,ada@example.test,secret,Sales\n");
        app(ImportExecutionLifecycle::class)->requestCancellation((string) $session->getKey(), $actor);
        $outbox = ImportOutboxEvent::query()->where('import_id', $session->getKey())->firstOrFail();

        Queue::fake();
        $this->artisan('smart-import:deliver-outbox', ['--limit' => 1])->assertExitCode(0);
        Queue::assertPushed(DeliverImportOutboxJob::class, fn (DeliverImportOutboxJob $job): bool =>
            $job->outboxEventId === (int) $outbox->getKey());

        $outbox->forceFill(['delivery_status' => 'failed', 'attempts' => 8])->save();
        Queue::fake();
        $this->artisan('smart-import:deliver-outbox', ['--limit' => 1, '--retry-failed' => true])->assertExitCode(0);
        $this->assertSame('pending', $outbox->fresh()->delivery_status);
        $this->assertSame(0, $outbox->fresh()->attempts);
        Queue::assertPushed(DeliverImportOutboxJob::class);
    }

    #[Test]
    public function missing_notification_recipients_do_not_block_outbox_delivery(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirmedSession($actor, "Ada,ada@example.test,secret,Sales\n");
        app(ImportExecutionLifecycle::class)->requestCancellation((string) $session->getKey(), $actor);
        $outbox = ImportOutboxEvent::query()->where('import_id', $session->getKey())->firstOrFail();
        $this->app->instance(ImportNotificationRecipientResolver::class, new class implements ImportNotificationRecipientResolver
        {
            public function recipients(ImportSession $session): array
            {
                return [];
            }
        });

        $this->assertSame('delivered', app(ImportOutboxDeliveryService::class)->deliver((int) $outbox->getKey()));
        $this->assertSame('delivered', $outbox->fresh()->delivery_status);
        $this->assertDatabaseCount('smart_import_notifications', 0);
    }

    #[Test]
    public function a_live_lease_blocks_a_second_relay_and_an_expired_lease_can_be_reclaimed(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirmedSession($actor, "Ada,ada@example.test,secret,Sales\n");
        app(ImportExecutionLifecycle::class)->requestCancellation((string) $session->getKey(), $actor);
        $outbox = ImportOutboxEvent::query()->where('import_id', $session->getKey())->firstOrFail();
        $outbox->forceFill([
            'delivery_status' => 'leased',
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinute(),
        ])->save();

        $delivery = app(ImportOutboxDeliveryService::class);
        $this->assertSame('skipped', $delivery->deliver((int) $outbox->getKey()));
        $this->assertDatabaseCount('smart_import_notifications', 0);

        $outbox->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $this->assertSame('delivered', $delivery->deliver((int) $outbox->getKey()));
        $this->assertDatabaseCount('smart_import_notifications', 1);
    }

    private function useRecipientResolver(): void
    {
        $this->app->instance(ImportNotificationRecipientResolver::class, new class implements ImportNotificationRecipientResolver
        {
            public function recipients(ImportSession $session): array
            {
                return [new ImportNotificationRecipient(GenericUser::class, '7')];
            }
        });
    }

    private function confirmedSession(GenericUser $actor, string $rows): ImportSession
    {
        $file = UploadedFile::fake()->createWithContent(
            'contacts.csv',
            "Name,Email,API token,Department\n".$rows,
        );
        $session = app(FileIntakeService::class)->upload($file, $actor);
        $session = app(ImportStagingService::class)->start(
            (string) $session->getKey(),
            $actor,
            'preview-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email', 3 => 'api_token', 4 => 'department'],
        );
        (new StageImportJob((string) $session->getKey(), 1, 1))->handle(app(ImportStagingService::class));
        $preview = app(ImportPreviewService::class);
        $preview->generate((string) $session->getKey(), $actor);

        return $preview->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision);
    }
}
