<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Events\ImportFinished;
use SmartImport\Jobs\ExecuteImportChunkJob;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Models\ImportAuditEntry;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportExecutionService;
use SmartImport\Services\ImportExecutionLifecycle;
use SmartImport\Services\ImportPreviewService;
use SmartImport\Services\ImportRetryService;
use SmartImport\Services\ImportStagingService;
use SmartImport\Tests\Fixtures\BlankValueImportDefinition;
use SmartImport\Tests\Fixtures\HookedPreviewImportDefinition;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\Fixtures\PreviewImportDefinition;
use SmartImport\Tests\Fixtures\SkipPreviewImportDefinition;
use SmartImport\Tests\TestCase;

class ImportExecutionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('import_target_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('api_token')->nullable();
            $table->string('department')->nullable();
            $table->string('nickname')->nullable();
            $table->string('phone')->nullable();
            $table->string('note')->nullable();
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Storage::fake('local');
        Queue::fake();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('smart-import.importers', [PreviewImportDefinition::class, BlankValueImportDefinition::class]);
        Gate::define('create', fn (): bool => true);
        Gate::define('view', fn (): bool => true);
        Gate::define('update', fn (): bool => true);
        Gate::define('queue', fn (): bool => true);
        Gate::define('cancel', fn (): bool => true);
        Gate::define('retry', fn (): bool => true);
        Gate::define('useImporter', fn (): bool => true);
        Gate::define('viewSensitiveImportFields', fn (): bool => false);
    }

    #[Test]
    public function creates_and_updates_allowlisted_fields_and_repeated_delivery_does_not_count_twice(): void
    {
        $existing = $this->target([
            'name' => 'Old contact',
            'email' => 'existing@example.test',
            'api_token' => 'old-secret',
            'department' => 'Old department',
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nUpdated,existing@example.test,new-secret,Sales\nNew contact,new@example.test,new-token,Marketing\n");
        $rows = $session->rows()->orderBy('source_row_number')->get();
        $execution = $this->app->make(ImportExecutionService::class);

        $first = $execution->processRow((string) $session->getKey(), $rows[0]->id, $session->confirmed_revision);
        $second = $execution->processRow((string) $session->getKey(), $rows[1]->id, $session->confirmed_revision);
        $repeated = $execution->processRow((string) $session->getKey(), $rows[0]->id, $session->confirmed_revision);

        $this->assertSame('updated', $first['execution_status']);
        $this->assertSame((string) $existing->getKey(), $first['target_id']);
        $this->assertSame('created', $second['execution_status']);
        $this->assertTrue($repeated['already_processed']);
        $this->assertDatabaseCount('import_target_models', 2);
        $this->assertSame('Updated', $existing->fresh()->name);
        $this->assertSame('new-secret', $existing->fresh()->api_token);
        $this->assertSame(['processed' => 2, 'created_count' => 1, 'updated_count' => 1, 'skipped_count' => 0, 'failed_count' => 0], $this->counters($session));
        $this->assertSame(1, $rows[0]->fresh()->attempt_count);
        $this->assertSame(ImportStatus::Queued, $session->fresh()->status);
    }

    #[Test]
    public function updates_preserve_blanks_and_apply_explicit_empty_and_null_values(): void
    {
        config()->set('smart-import.importers', [BlankValueImportDefinition::class]);
        $existing = $this->target([
            'email' => 'blank@example.test',
            'nickname' => 'Keep this value',
            'phone' => '12345',
            'department' => 'Keep this too',
            'note' => 'Old note',
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm(
            $actor,
            "Email,Nickname,Phone,Department,Note\nblank@example.test,,, ,[NULL]\n",
            'blank-values',
            [1 => 'email', 2 => 'nickname', 3 => 'phone', 4 => 'department', 5 => 'note'],
        );

        $row = $session->rows()->firstOrFail();
        $result = $this->app->make(ImportExecutionService::class)
            ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);

        $this->assertSame('updated', $result['execution_status']);
        $this->assertSame('Keep this value', $existing->fresh()->nickname);
        $this->assertSame('', $existing->fresh()->phone);
        $this->assertNull($existing->fresh()->department);
        $this->assertNull($existing->fresh()->note);
        $this->assertSame(1, $session->fresh()->updated_count);
    }

    #[Test]
    public function a_target_created_after_confirmation_fails_instead_of_writing_an_unreviewed_update(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nReviewed new contact,new@example.test,secret,Sales\n");
        $row = $session->rows()->firstOrFail();
        $this->target(['name' => 'Concurrent contact', 'email' => 'new@example.test']);

        $result = $this->app->make(ImportExecutionService::class)
            ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);

        $this->assertSame('failed', $result['execution_status']);
        $this->assertSame('target_changed_after_preview', $row->fresh()->last_error_code);
        $this->assertSame('Concurrent contact', ImportTargetModel::query()->where('email', 'new@example.test')->firstOrFail()->name);
        $this->assertSame(1, $session->fresh()->failed_count);
        $this->assertSame(1, $session->fresh()->processed);
    }

    #[Test]
    public function changed_mapped_target_values_after_confirmation_fail_without_overwriting_them(): void
    {
        $target = $this->target([
            'name' => 'Before review',
            'email' => 'existing@example.test',
            'api_token' => 'old-secret',
            'department' => 'Sales',
            'notes' => 'Operator note',
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm(
            $actor,
            "Name,Email,API token,Department\nImported name,existing@example.test,new-secret,Sales\n",
        );
        $row = $session->rows()->firstOrFail();
        $target->forceFill(['department' => 'Changed by another user'])->save();

        $result = $this->app->make(ImportExecutionService::class)
            ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);

        $this->assertSame('failed', $result['execution_status']);
        $this->assertSame('target_changed_after_preview', $row->fresh()->last_error_code);
        $this->assertSame('Before review', $target->fresh()->name);
        $this->assertSame('old-secret', $target->fresh()->api_token);
        $this->assertSame('Changed by another user', $target->fresh()->department);
    }

    #[Test]
    public function changes_to_unmapped_target_fields_do_not_conflict_or_get_overwritten(): void
    {
        $target = $this->target([
            'name' => 'Before review',
            'email' => 'existing@example.test',
            'api_token' => 'old-secret',
            'department' => 'Sales',
            'notes' => 'Original note',
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nImported name,existing@example.test,new-secret,Sales\n");
        $row = $session->rows()->firstOrFail();
        $target->forceFill(['notes' => 'Changed outside the import'])->save();

        $result = $this->app->make(ImportExecutionService::class)
            ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);

        $this->assertSame('updated', $result['execution_status']);
        $this->assertSame('Imported name', $target->fresh()->name);
        $this->assertSame('Changed outside the import', $target->fresh()->notes);
    }

    #[Test]
    public function missing_legacy_target_snapshot_fails_closed_without_writing(): void
    {
        $target = $this->target([
            'name' => 'Before review',
            'email' => 'existing@example.test',
            'api_token' => 'old-secret',
            'department' => 'Sales',
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nImported name,existing@example.test,new-secret,Sales\n");
        $row = $session->rows()->firstOrFail();
        $preview = $row->preview;
        unset($preview['target_snapshot']);
        DB::table('smart_import_rows')->where('id', $row->getKey())->update([
            'preview' => json_encode($preview, JSON_THROW_ON_ERROR),
        ]);

        $result = $this->app->make(ImportExecutionService::class)
            ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);

        $this->assertSame('failed', $result['execution_status']);
        $this->assertSame('target_changed_after_preview', $row->fresh()->last_error_code);
        $this->assertSame('Before review', $target->fresh()->name);
        $this->assertSame('old-secret', $target->fresh()->api_token);
    }

    #[Test]
    public function deleting_a_reviewed_target_fails_without_creating_a_replacement(): void
    {
        $target = $this->target([
            'name' => 'Before review',
            'email' => 'existing@example.test',
            'api_token' => 'old-secret',
            'department' => 'Sales',
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nImported name,existing@example.test,new-secret,Sales\n");
        $row = $session->rows()->firstOrFail();
        $target->delete();

        $result = $this->app->make(ImportExecutionService::class)
            ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);

        $this->assertSame('failed', $result['execution_status']);
        $this->assertSame('target_changed_after_preview', $row->fresh()->last_error_code);
        $this->assertDatabaseCount('import_target_models', 0);
    }

    #[Test]
    public function unchanged_upsert_rows_are_skipped_without_firing_target_update_events(): void
    {
        $target = $this->target([
            'name' => 'Existing contact',
            'email' => 'existing@example.test',
            'api_token' => 'same-secret',
            'department' => 'Sales',
        ]);
        $updatedEvents = 0;
        Event::listen('eloquent.updated: '.ImportTargetModel::class, function () use (&$updatedEvents): void {
            $updatedEvents++;
        });
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm(
            $actor,
            "Name,Email,API token,Department\nExisting contact,existing@example.test,same-secret,Sales\n",
        );
        $row = $session->rows()->firstOrFail();
        $result = $this->app->make(ImportExecutionService::class)
            ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);

        $this->assertSame('skipped', $result['execution_status']);
        $this->assertSame('unchanged', $row->fresh()->preview['reason']);
        $this->assertSame(0, $updatedEvents);
        $this->assertSame(1, $session->fresh()->skipped_count);
        $this->assertSame(0, $session->fresh()->updated_count);
    }

    #[Test]
    public function equivalent_boolean_target_value_is_skipped_without_saving_the_target(): void
    {
        $target = $this->target([
            'name' => 'Existing contact',
            'email' => 'active@example.test',
            'is_active' => 1,
        ]);
        $updatedEvents = 0;
        Event::listen('eloquent.updated: '.ImportTargetModel::class, function () use (&$updatedEvents): void {
            $updatedEvents++;
        });
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm(
            $actor,
            "Name,Email,Active\nExisting contact,active@example.test,1\n",
            mapping: [1 => 'name', 2 => 'email', 3 => 'is_active'],
        );
        $row = $session->rows()->firstOrFail();

        $result = app(ImportExecutionService::class)->processRow(
            (string) $session->getKey(),
            (int) $row->getKey(),
            (int) $session->confirmed_revision,
        );

        $this->assertSame('skipped', $result['execution_status']);
        $this->assertSame('unchanged', $row->fresh()->preview['reason']);
        $this->assertSame(0, $updatedEvents);
        $this->assertSame(1, $session->fresh()->skipped_count);
        $this->assertSame(0, $session->fresh()->updated_count);
        $this->assertSame(1, $target->fresh()->getRawOriginal('is_active'));
    }

    #[Test]
    public function a_real_boolean_value_change_is_written_after_review(): void
    {
        $target = $this->target([
            'name' => 'Existing contact',
            'email' => 'active@example.test',
            'is_active' => 0,
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm(
            $actor,
            "Name,Email,Active\nExisting contact,active@example.test,1\n",
            mapping: [1 => 'name', 2 => 'email', 3 => 'is_active'],
        );
        $row = $session->rows()->firstOrFail();

        $result = app(ImportExecutionService::class)->processRow(
            (string) $session->getKey(),
            (int) $row->getKey(),
            (int) $session->confirmed_revision,
        );

        $this->assertSame('updated', $result['execution_status']);
        $this->assertSame(1, $target->fresh()->getRawOriginal('is_active'));
        $this->assertSame(1, $session->fresh()->updated_count);
    }

    #[Test]
    public function a_boolean_target_changed_after_review_fails_the_strict_snapshot_check(): void
    {
        $target = $this->target([
            'name' => 'Existing contact',
            'email' => 'active@example.test',
            'is_active' => 0,
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm(
            $actor,
            "Name,Email,Active\nChanged name,active@example.test,1\n",
            mapping: [1 => 'name', 2 => 'email', 3 => 'is_active'],
        );
        $row = $session->rows()->firstOrFail();
        $target->forceFill(['is_active' => 1])->save();

        $result = app(ImportExecutionService::class)->processRow(
            (string) $session->getKey(),
            (int) $row->getKey(),
            (int) $session->confirmed_revision,
        );

        $this->assertSame('failed', $result['execution_status']);
        $this->assertSame('target_changed_after_preview', $row->fresh()->last_error_code);
        $this->assertSame('Existing contact', $target->fresh()->name);
        $this->assertSame(1, $target->fresh()->getRawOriginal('is_active'));
    }

    #[Test]
    public function a_confirmed_legacy_boolean_update_keeps_its_reviewed_action(): void
    {
        $target = $this->target([
            'name' => 'Existing contact',
            'email' => 'active@example.test',
            'is_active' => 1,
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = app(FileIntakeService::class)->upload(
            UploadedFile::fake()->createWithContent(
                'contacts.csv',
                "Name,Email,Active\nExisting contact,active@example.test,1\n",
            ),
            $actor,
        );
        $session = app(ImportStagingService::class)->start(
            (string) $session->getKey(),
            $actor,
            'preview-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email', 3 => 'is_active'],
        );
        (new StageImportJob((string) $session->getKey(), 1, 1))->handle(app(ImportStagingService::class));
        $session = $session->fresh();
        $previewService = app(ImportPreviewService::class);
        $previewService->generate((string) $session->getKey(), $actor);
        $row = $session->rows()->firstOrFail();
        $legacyPreview = $row->preview;
        $legacyPreview['action'] = 'update';
        unset($legacyPreview['reason']);
        DB::table('smart_import_rows')->where('id', $row->getKey())->update([
            'preview' => json_encode($legacyPreview, JSON_THROW_ON_ERROR),
        ]);
        $confirmed = $previewService->confirm(
            (string) $session->getKey(),
            $actor,
            (int) $session->staging_revision,
        );
        $updatedEvents = 0;
        Event::listen('eloquent.updated: '.ImportTargetModel::class, function () use (&$updatedEvents): void {
            $updatedEvents++;
        });

        $result = app(ImportExecutionService::class)->processRow(
            (string) $confirmed->getKey(),
            (int) $row->getKey(),
            (int) $confirmed->confirmed_revision,
        );

        $this->assertSame('updated', $result['execution_status']);
        $this->assertSame(0, $updatedEvents);
        $this->assertSame(1, $confirmed->fresh()->updated_count);
        $this->assertSame(1, $target->fresh()->getRawOriginal('is_active'));
    }

    #[Test]
    public function duplicate_file_rows_are_counted_as_failures_without_target_writes(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm(
            $actor,
            "Name,Email,API token,Department\nFirst,duplicate@example.test,one,Sales\nSecond,duplicate@example.test,two,Sales\n",
            forceValidRowsAfterStaging: true,
        );

        foreach ($session->rows()->orderBy('id')->get() as $row) {
            $this->app->make(ImportExecutionService::class)
                ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);
        }

        $this->assertDatabaseCount('import_target_models', 0);
        $this->assertSame(2, $session->fresh()->failed_count);
        $this->assertSame(2, $session->fresh()->processed);
        $this->assertSame(2, ImportRow::query()->where('import_id', $session->getKey())->where('last_error_code', 'duplicate_in_file')->count());
    }

    #[Test]
    public function skip_policy_counts_existing_rows_without_changing_the_target(): void
    {
        config()->set('smart-import.importers', [SkipPreviewImportDefinition::class]);
        $existing = $this->target(['name' => 'Keep this contact', 'email' => 'existing@example.test']);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm(
            $actor,
            "Name,Email,API token,Department\nOverwrite attempt,existing@example.test,new-secret,Sales\nNew contact,new@example.test,new-token,Marketing\n",
            'skip-preview-contacts',
        );

        foreach ($session->rows()->orderBy('id')->get() as $row) {
            $this->app->make(ImportExecutionService::class)
                ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);
        }

        $this->assertSame('Keep this contact', $existing->fresh()->name);
        $this->assertSame(['processed' => 2, 'created_count' => 1, 'updated_count' => 0, 'skipped_count' => 1, 'failed_count' => 0], $this->counters($session));
        $this->assertSame(['skipped', 'created'], $session->rows()->orderBy('id')->pluck('execution_status')->all());
    }

    #[Test]
    public function target_write_rolls_back_if_the_durable_counter_cannot_be_incremented(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nNew contact,new@example.test,secret,Sales\n");
        $row = $session->rows()->firstOrFail();
        ImportSession::query()->whereKey($session->getKey())->update(['execution_total' => 0]);

        try {
            $this->app->make(ImportExecutionService::class)
                ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);
            $this->fail('The failed counter invariant must roll back the target write.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('counter could not be updated', $exception->getMessage());
        }

        $this->assertDatabaseCount('import_target_models', 0);
        $this->assertSame('pending', $row->fresh()->execution_status);
        $this->assertSame(0, $session->fresh()->processed);
    }

    #[Test]
    public function lifecycle_and_row_hooks_run_once_for_a_confirmed_attempt(): void
    {
        config()->set('smart-import.importers', [HookedPreviewImportDefinition::class]);
        HookedPreviewImportDefinition::$calls = [];
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nNew contact,new@example.test,secret,Sales\n");
        $row = $session->rows()->firstOrFail();

        $job = new ExecuteImportChunkJob(
            (string) $session->getKey(),
            $session->current_attempt,
            $session->confirmed_revision,
            [$row->id],
        );
        $execution = $this->app->make(ImportExecutionService::class);
        $lifecycle = $this->app->make(ImportExecutionLifecycle::class);
        $job->handle($execution, $lifecycle);

        $this->assertSame([
            ['hook' => 'before_import', 'session_id' => (string) $session->getKey()],
            ['hook' => 'before_row', 'session_id' => (string) $session->getKey(), 'row_id' => $row->id],
            ['hook' => 'after_row', 'session_id' => (string) $session->getKey(), 'row_id' => $row->id],
            ['hook' => 'after_import', 'session_id' => (string) $session->getKey()],
        ], HookedPreviewImportDefinition::$calls);

        $job->handle($execution, $lifecycle);

        $this->assertCount(4, HookedPreviewImportDefinition::$calls);
        $this->assertSame(ImportStatus::Completed, $session->fresh()->status);
    }

    #[Test]
    public function queued_cancellation_finishes_without_processing_any_rows(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nAda,ada@example.test,ada-token,Sales\nGrace,grace@example.test,grace-token,Support\n");

        $cancelled = $this->app->make(ImportExecutionLifecycle::class)->requestCancellation(
            (string) $session->getKey(),
            $actor,
        );

        $this->assertSame(ImportStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancel_requested_at);
        $this->assertNotNull($cancelled->finished_at);
        $this->assertSame(0, $cancelled->processed);
        $this->assertSame(2, $cancelled->execution_total);
        $this->assertSame(2, $cancelled->rows()->where('execution_status', 'pending')->count());
    }

    #[Test]
    public function active_cancellation_waits_for_a_worker_boundary_and_preserves_committed_counts(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nAda,ada@example.test,ada-token,Sales\nGrace,grace@example.test,grace-token,Support\n");
        $lifecycle = $this->app->make(ImportExecutionLifecycle::class);
        $this->assertTrue($lifecycle->beginChunk((string) $session->getKey(), 1, $session->confirmed_revision));

        $cancelling = $lifecycle->requestCancellation((string) $session->getKey(), $actor);
        $this->assertSame(ImportStatus::Cancelling, $cancelling->status);
        $this->assertNotNull($cancelling->cancel_requested_at);

        $this->assertTrue($lifecycle->completeIfReady((string) $session->getKey(), 1, $session->confirmed_revision));
        $cancelled = $session->fresh();

        $this->assertSame(ImportStatus::Cancelled, $cancelled->status);
        $this->assertSame(0, $cancelled->processed);
        $this->assertSame(2, $cancelled->rows()->where('execution_status', 'pending')->count());
    }

    #[Test]
    public function cancellation_requires_the_explicit_cancel_ability(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nAda,ada@example.test,ada-token,Sales\n");
        Gate::define('cancel', static fn (): bool => false);

        try {
            $this->app->make(ImportExecutionLifecycle::class)->requestCancellation(
                (string) $session->getKey(),
                $actor,
            );
            $this->fail('Cancellation must require the explicit cancel ability.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame(ImportStatus::Queued, $session->fresh()->status);
        }
    }

    #[Test]
    public function retry_creates_a_new_attempt_for_failed_rows_and_preserves_successful_outcomes(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm(
            $actor,
            "Name,Email,API token,Department\nAda,ada@example.test,ada-token,Sales\nGrace,not-an-email,grace-token,Support\n",
            forceValidRowsAfterStaging: true,
        );
        $rows = $session->rows()->orderBy('source_row_number')->get();
        $job = new ExecuteImportChunkJob(
            (string) $session->getKey(),
            $session->current_attempt,
            $session->confirmed_revision,
            $rows->modelKeys(),
        );
        $execution = $this->app->make(ImportExecutionService::class);
        $lifecycle = $this->app->make(ImportExecutionLifecycle::class);
        $job->handle($execution, $lifecycle);

        $completed = $session->fresh();
        $this->assertSame(ImportStatus::Completed, $completed->status);
        $this->assertSame(2, $completed->processed);
        $this->assertSame(1, $completed->created_count);
        $this->assertSame(1, $completed->failed_count);
        $this->assertSame('created', $rows[0]->fresh()->execution_status);
        $this->assertSame('failed', $rows[1]->fresh()->execution_status);

        $retrying = $this->app->make(ImportRetryService::class)->retryFailedRows((string) $session->getKey(), $actor);

        $this->assertSame(ImportStatus::Queued, $retrying->status);
        $this->assertSame(2, $retrying->current_attempt);
        $this->assertSame(1, $retrying->execution_total);
        $this->assertSame(0, $retrying->processed);
        $this->assertSame('created', $rows[0]->fresh()->execution_status);
        $this->assertSame('pending', $rows[1]->fresh()->execution_status);
        $this->assertSame(1, count($rows[1]->fresh()->execution_history));
        Queue::assertPushed(ExecuteImportChunkJob::class, fn (ExecuteImportChunkJob $queued): bool =>
            $queued->attemptId === 2 && $queued->rowIds === [$rows[1]->id]);

        (new ExecuteImportChunkJob(
            (string) $retrying->getKey(),
            $retrying->current_attempt,
            $retrying->confirmed_revision,
            [$rows[1]->id],
        ))->handle($execution, $lifecycle);

        $retried = $session->fresh();
        $this->assertSame(ImportStatus::Completed, $retried->status);
        $this->assertSame(1, $retried->processed);
        $this->assertSame(0, $retried->created_count);
        $this->assertSame(1, $retried->failed_count);
        $this->assertSame('created', $rows[0]->fresh()->execution_status);
        $this->assertSame(2, count($rows[1]->fresh()->execution_history));
        $this->assertSame('completed', $retried->attempt_history[0]['status']);
        $this->assertSame(2, $retried->attempt_history[0]['processed']);
        $this->assertSame('failed_rows_only', $retried->attempt_history[1]['retry']);
        $this->assertSame('completed', $retried->attempt_history[1]['status']);
        $retryAudit = ImportAuditEntry::query()
            ->where('import_id', $session->getKey())
            ->where('event', 'import.retry_started')
            ->sole();
        $this->assertSame(2, $retryAudit->attempt_id);
        $this->assertSame(['attempt_id' => 2, 'retry_of_attempt' => 1, 'execution_total' => 1], $retryAudit->metadata);
    }

    #[Test]
    public function failed_row_retry_updates_large_attempts_in_bounded_locked_batches(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nAda,ada@example.test,ada-token,Sales\n");
        $row = $session->rows()->firstOrFail();
        $row->forceFill([
            'execution_status' => 'failed',
            'last_error_code' => 'temporary_failure',
            'last_error_message' => 'Safe test failure.',
            'completed_at' => now(),
        ])->save();

        $attributes = $row->fresh()->getAttributes();
        unset($attributes['id']);
        $now = now();
        $attributes['execution_status'] = 'failed';
        $attributes['last_error_code'] = 'temporary_failure';
        $attributes['last_error_message'] = 'Safe test failure.';
        $attributes['completed_at'] = $now;
        $attributes['created_at'] = $now;
        $attributes['updated_at'] = $now;
        $additionalRows = [];

        for ($sourceRow = 3; $sourceRow <= 502; $sourceRow++) {
            $additionalRows[] = array_merge($attributes, ['source_row_number' => $sourceRow]);
        }

        foreach (array_chunk($additionalRows, 50) as $chunk) {
            DB::table('smart_import_rows')->insert($chunk);
        }

        $session->forceFill([
            'status' => ImportStatus::Completed,
            'execution_total' => 501,
            'processed' => 501,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 501,
            'finished_at' => now(),
        ])->save();

        $rowUpdateQueries = 0;
        DB::listen(static function ($query) use (&$rowUpdateQueries): void {
            $sql = strtolower(ltrim($query->sql));

            if (str_starts_with($sql, 'update') && str_contains($sql, 'smart_import_rows')) {
                $rowUpdateQueries++;
            }
        });

        $retrying = $this->app->make(ImportRetryService::class)->retryFailedRows((string) $session->getKey(), $actor);

        $this->assertSame(ImportStatus::Queued, $retrying->status);
        $this->assertSame(501, $retrying->execution_total);
        $this->assertSame(501, $session->rows()->where('execution_status', 'pending')->count());
        $this->assertSame(0, $session->rows()->whereNotNull('last_error_code')->count());
        $this->assertLessThanOrEqual(3, $rowUpdateQueries, 'Failed rows must be reset in 250-row batches, not one update per row.');
        $retryJobs = Queue::pushed(ExecuteImportChunkJob::class, fn (ExecuteImportChunkJob $job): bool =>
            $job->sessionId === (string) $session->getKey() && $job->attemptId === 2
        );
        $this->assertCount(6, $retryJobs);
        $this->assertSame(501, $retryJobs->sum(fn (ExecuteImportChunkJob $job): int => count($job->rowIds)));
    }

    #[Test]
    public function execution_jobs_use_the_configured_bounded_tries_and_backoff_schedule(): void
    {
        $job = new ExecuteImportChunkJob('session-id', 1, 1, [1]);

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30, 60], $job->backoff);

        config()->set('smart-import.execution.job_tries', 11);

        $this->expectException(\InvalidArgumentException::class);
        new ExecuteImportChunkJob('session-id', 1, 1, [1]);
    }

    #[Test]
    public function failed_row_retry_requires_the_explicit_retry_ability(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nAda,ada@example.test,ada-token,Sales\n");
        Gate::define('retry', static fn (): bool => false);

        try {
            $this->app->make(ImportRetryService::class)->retryFailedRows((string) $session->getKey(), $actor);
            $this->fail('Retry must require the explicit retry ability.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame(ImportStatus::Queued, $session->fresh()->status);
        }
    }

    #[Test]
    public function archived_completed_imports_cannot_retry_failed_rows(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nAda,ada@example.test,ada-token,Sales\n");
        $row = $session->rows()->firstOrFail();
        $row->forceFill(['execution_status' => 'failed', 'last_error_code' => 'test_failure'])->save();
        $session->forceFill([
            'status' => ImportStatus::Completed,
            'processed' => 1,
            'failed_count' => 1,
            'intake_status' => IntakeStatus::Archived,
        ])->save();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('archived');
        $this->app->make(ImportRetryService::class)->retryFailedRows((string) $session->getKey(), $actor);
    }

    #[Test]
    public function archived_sessions_cannot_execute_pending_rows(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nAda,ada@example.test,ada-token,Sales\n");
        $row = $session->rows()->firstOrFail();
        $session->forceFill(['intake_status' => IntakeStatus::Archived])->save();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('archived');
        $this->app->make(ImportExecutionService::class)
            ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);
    }

    #[Test]
    public function database_unique_conflicts_are_recorded_as_safe_row_failures(): void
    {
        Schema::table('import_target_models', function (Blueprint $table): void {
            $table->unique('name');
        });
        $this->target(['name' => 'Already taken', 'email' => 'other@example.test']);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nAlready taken,new@example.test,secret,Sales\n");
        $row = $session->rows()->firstOrFail();

        $result = $this->app->make(ImportExecutionService::class)
            ->processRow((string) $session->getKey(), $row->id, $session->confirmed_revision);

        $this->assertSame('failed', $result['execution_status']);
        $this->assertSame('target_unique_conflict', $row->fresh()->last_error_code);
        $this->assertSame('failed', $row->fresh()->execution_status);
        $this->assertSame(1, $session->fresh()->failed_count);
        $this->assertSame(1, $session->fresh()->processed);
        $this->assertDatabaseCount('import_target_models', 1);
    }

    #[Test]
    public function a_second_confirmed_import_rechecks_a_key_created_by_the_first_import(): void
    {
        Schema::table('import_target_models', function (Blueprint $table): void {
            $table->unique('email');
        });
        $actor = new GenericUser(['id' => 7]);
        $first = $this->confirm($actor, "Name,Email,API token,Department\nFirst reviewed name,race@example.test,one,Sales\n");
        $second = $this->confirm($actor, "Name,Email,API token,Department\nSecond reviewed name,race@example.test,two,Marketing\n");
        $firstRow = $first->rows()->firstOrFail();
        $secondRow = $second->rows()->firstOrFail();
        $execution = $this->app->make(ImportExecutionService::class);

        $firstResult = $execution->processRow((string) $first->getKey(), $firstRow->id, $first->confirmed_revision);
        $secondResult = $execution->processRow((string) $second->getKey(), $secondRow->id, $second->confirmed_revision);

        $this->assertSame('created', $firstResult['execution_status']);
        $this->assertSame('failed', $secondResult['execution_status']);
        $this->assertSame('target_changed_after_preview', $secondRow->fresh()->last_error_code);
        $this->assertSame('First reviewed name', ImportTargetModel::query()->where('email', 'race@example.test')->firstOrFail()->name);
        $this->assertDatabaseCount('import_target_models', 1);
        $this->assertSame(1, $first->fresh()->created_count);
        $this->assertSame(1, $second->fresh()->failed_count);
    }

    #[Test]
    public function chunk_job_commits_each_row_outcome_and_duplicate_chunk_delivery_keeps_progress_unchanged(): void
    {
        Event::fake([ImportFinished::class]);
        $existing = $this->target(['name' => 'Before', 'email' => 'existing@example.test']);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->confirm($actor, "Name,Email,API token,Department\nAfter,existing@example.test,secret,Sales\nNew,new@example.test,new-secret,Marketing\n");
        $rows = $session->rows()->orderBy('id')->get();
        $job = new ExecuteImportChunkJob(
            (string) $session->getKey(),
            $session->current_attempt,
            $session->confirmed_revision,
            $rows->modelKeys(),
        );

        $job->handle(
            $this->app->make(ImportExecutionService::class),
            $this->app->make(ImportExecutionLifecycle::class),
        );

        $committed = $session->fresh();
        $this->assertSame(2, $committed->processed);
        $this->assertSame(1, $committed->updated_count);
        $this->assertSame(1, $committed->created_count);
        $this->assertSame(['updated', 'created'], $session->rows()->orderBy('id')->pluck('execution_status')->all());
        $this->assertSame('After', $existing->fresh()->name);
        $this->assertNotNull($committed->heartbeat_at);
        $this->assertSame(ImportStatus::Completed, $committed->status);
        $this->assertNotNull($committed->started_at);
        $this->assertNotNull($committed->finished_at);
        Event::assertDispatchedTimes(ImportFinished::class, 1);
        Event::assertDispatched(ImportFinished::class, fn (ImportFinished $event): bool =>
            $event->sessionId === (string) $session->getKey()
            && $event->attemptId === 1
            && $event->status === ImportStatus::Completed
            && $event->processed === 2
        );

        $job->handle(
            $this->app->make(ImportExecutionService::class),
            $this->app->make(ImportExecutionLifecycle::class),
        );

        $this->assertSame(2, $session->fresh()->processed);
        $this->assertSame(1, $session->fresh()->created_count);
        $this->assertSame(1, $session->fresh()->updated_count);
        $this->assertSame(1, $rows[0]->fresh()->attempt_count);
        Event::assertDispatchedTimes(ImportFinished::class, 1);
    }

    /** @param array<int, string|null> $mapping */
    private function confirm(
        GenericUser $actor,
        string $contents,
        string $importerKey = 'preview-contacts',
        array $mapping = [1 => 'name', 2 => 'email', 3 => 'api_token', 4 => 'department'],
        bool $forceValidRowsAfterStaging = false,
    ): ImportSession {
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $contents);
        $session = $this->app->make(FileIntakeService::class)->upload($file, $actor);
        $staging = $this->app->make(ImportStagingService::class)->start(
            (string) $session->getKey(),
            $actor,
            $importerKey,
            0,
            1,
            $mapping,
        );
        (new StageImportJob((string) $session->getKey(), 1, 1))->handle($this->app->make(ImportStagingService::class));

        if ($forceValidRowsAfterStaging) {
            $staging->rows()->update(['validation_status' => 'valid', 'validation_errors' => null]);
        }

        $staging = $staging->fresh();
        $previews = $this->app->make(ImportPreviewService::class);
        $previews->generate((string) $staging->getKey(), $actor);

        return $previews->confirm((string) $staging->getKey(), $actor, (int) $staging->staging_revision);
    }

    /** @param array<string, mixed> $attributes */
    private function target(array $attributes): ImportTargetModel
    {
        $target = new ImportTargetModel();
        $target->forceFill($attributes);
        $target->save();

        return $target;
    }

    /** @return array<string, int> */
    private function counters(ImportSession $session): array
    {
        $fresh = $session->fresh();

        return [
            'processed' => $fresh->processed,
            'created_count' => $fresh->created_count,
            'updated_count' => $fresh->updated_count,
            'skipped_count' => $fresh->skipped_count,
            'failed_count' => $fresh->failed_count,
        ];
    }
}
