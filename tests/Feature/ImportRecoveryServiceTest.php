<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\ImportStatus;
use SmartImport\Jobs\ExecuteImportChunkJob;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Models\ImportSession;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportExecutionLifecycle;
use SmartImport\Services\ImportExecutionService;
use SmartImport\Services\ImportPreviewService;
use SmartImport\Services\ImportRecoveryService;
use SmartImport\Services\ImportStagingService;
use SmartImport\Tests\Fixtures\PreviewImportDefinition;
use SmartImport\Tests\TestCase;

class ImportRecoveryServiceTest extends TestCase
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
        config()->set('smart-import.recovery.max_recoveries', 3);
        config()->set('smart-import.execution.chunk_size', 1);

        foreach (['create', 'view', 'update', 'queue', 'cancel', 'useImporter'] as $ability) {
            Gate::define($ability, static fn (): bool => true);
        }
    }

    #[Test]
    public function dry_run_does_not_change_a_session_and_recovery_dispatches_only_pending_rows_in_the_same_attempt(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Ada,ada@example.test,token,Sales\nGrace,grace@example.test,token,Support\n");
        $rows = $session->rows()->orderBy('source_row_number')->get();
        $lifecycle = app(ImportExecutionLifecycle::class);
        $execution = app(ImportExecutionService::class);
        $this->assertTrue($lifecycle->beginChunk((string) $session->getKey(), 1, $session->confirmed_revision));
        $execution->processRow((string) $session->getKey(), $rows[0]->id, $session->confirmed_revision, 1);
        ImportSession::query()->whereKey($session->getKey())->update(['heartbeat_at' => now()->subHour()]);

        $beforeDryRun = $session->fresh();
        $counts = app(ImportRecoveryService::class)->recoverStale(100, true);

        $this->assertSame(1, $counts['scanned']);
        $this->assertSame(1, $counts['would_recover']);
        $this->assertSame(0, $counts['execution_requeued']);
        $this->assertSame($beforeDryRun->status, $session->fresh()->status);
        $this->assertSame($beforeDryRun->recovery_count, $session->fresh()->recovery_count);

        $counts = app(ImportRecoveryService::class)->recoverStale();

        $this->assertSame(1, $counts['execution_requeued']);
        $this->assertSame(ImportStatus::Importing, $session->fresh()->status);
        $this->assertSame(1, $session->fresh()->current_attempt);
        $this->assertSame(1, $session->fresh()->recovery_count);
        $this->assertSame(1, $session->fresh()->processed);
        Queue::assertPushed(ExecuteImportChunkJob::class, fn (ExecuteImportChunkJob $job): bool =>
            $job->attemptId === 1 && $job->rowIds === [$rows[1]->id], 2);

        $secondPass = app(ImportRecoveryService::class)->recoverStale();
        $this->assertSame(0, $secondPass['scanned']);
    }

    #[Test]
    public function inconsistent_durable_counters_fail_safely_without_dispatching_rows(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Ada,ada@example.test,token,Sales\n");
        ImportSession::query()->whereKey($session->getKey())->update([
            'processed' => 1,
            'heartbeat_at' => now()->subHour(),
        ]);

        $counts = app(ImportRecoveryService::class)->recoverStale();
        $failed = $session->fresh();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(ImportStatus::Failed, $failed->status);
        $this->assertSame('Saved row outcomes do not match the durable execution counters. Review this import before retrying.', $failed->error_summary);
        $this->assertSame('pending', $session->rows()->firstOrFail()->execution_status);
        Queue::assertPushed(ExecuteImportChunkJob::class, 1);
    }

    #[Test]
    public function stale_failed_staging_is_requeued_and_the_recovery_command_reports_it(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $file = UploadedFile::fake()->createWithContent(
            'contacts.csv',
            "Name,Email,API token,Department\nAda,ada@example.test,token,Sales\n",
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
        app(ImportStagingService::class)->fail((string) $session->getKey(), $session->mapping_revision, $session->staging_revision);
        ImportSession::query()->whereKey($session->getKey())->update(['heartbeat_at' => now()->subHour()]);

        $exitCode = Artisan::call('smart-import:recover', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 can be recovered or settled', Artisan::output());
        $this->assertSame(ImportStatus::Failed, $session->fresh()->status);

        $counts = app(ImportRecoveryService::class)->recoverStale();

        $this->assertSame(1, $counts['stage_requeued']);
        $this->assertSame(ImportStatus::Staging, $session->fresh()->status);
        $this->assertSame(1, $session->fresh()->recovery_count);
        Queue::assertPushed(StageImportJob::class, fn (StageImportJob $job): bool =>
            $job->sessionId === (string) $session->getKey(), 2);
    }

    #[Test]
    public function a_session_at_its_recovery_limit_is_failed_with_a_safe_summary(): void
    {
        config()->set('smart-import.recovery.max_recoveries', 1);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Ada,ada@example.test,token,Sales\nGrace,grace@example.test,token,Support\n");
        $session->forceFill([
            'status' => ImportStatus::Importing,
            'recovery_count' => 1,
            'heartbeat_at' => now()->subHour(),
        ])->save();

        $counts = app(ImportRecoveryService::class)->recoverStale();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(ImportStatus::Failed, $session->fresh()->status);
        $this->assertSame(1, $session->fresh()->recovery_count);
        $this->assertStringContainsString('safe recovery limit was reached', $session->fresh()->error_summary);
    }

    #[Test]
    public function stale_cancellation_is_finalized_without_processing_pending_rows(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Ada,ada@example.test,token,Sales\nGrace,grace@example.test,token,Support\n");
        $lifecycle = app(ImportExecutionLifecycle::class);
        $this->assertTrue($lifecycle->beginChunk((string) $session->getKey(), 1, $session->confirmed_revision));
        $lifecycle->requestCancellation((string) $session->getKey(), $actor);
        ImportSession::query()->whereKey($session->getKey())->update(['heartbeat_at' => now()->subHour()]);

        $counts = app(ImportRecoveryService::class)->recoverStale();

        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame(ImportStatus::Cancelled, $session->fresh()->status);
        $this->assertSame(0, $session->fresh()->processed);
        $this->assertSame(2, $session->rows()->where('execution_status', 'pending')->count());
    }

    #[Test]
    public function a_crash_after_the_last_row_commit_is_reconciled_as_completed(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Ada,ada@example.test,token,Sales\n");
        $lifecycle = app(ImportExecutionLifecycle::class);
        $this->assertTrue($lifecycle->beginChunk((string) $session->getKey(), 1, $session->confirmed_revision));
        app(ImportExecutionService::class)->processRow(
            (string) $session->getKey(),
            $session->rows()->firstOrFail()->id,
            $session->confirmed_revision,
            1,
        );
        ImportSession::query()->whereKey($session->getKey())->update(['heartbeat_at' => now()->subHour()]);

        $counts = app(ImportRecoveryService::class)->recoverStale();

        $this->assertSame(1, $counts['completed']);
        $this->assertSame(ImportStatus::Completed, $session->fresh()->status);
        $this->assertSame(1, $session->fresh()->processed);
        $this->assertSame(0, $session->rows()->where('execution_status', 'pending')->count());
    }

    private function confirm(GenericUser $actor, string $rows): ImportSession
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

        return $preview->confirm((string) $session->getKey(), $actor);
    }
}
