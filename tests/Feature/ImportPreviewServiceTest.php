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
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\ImportStatus;
use SmartImport\Events\ImportFinished;
use SmartImport\Jobs\ExecuteImportChunkJob;
use SmartImport\Jobs\GenerateImportPreviewJob;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportPreviewService;
use SmartImport\Services\ImportStagingService;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImportExecutionLifecycle;
use SmartImport\Services\ImportExecutionService;
use SmartImport\Services\ImporterRegistry;
use SmartImport\Services\StagedRowService;
use SmartImport\Tests\Fixtures\SkipPreviewImportDefinition;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\Fixtures\InvalidRowExclusionImportDefinition;
use SmartImport\Tests\Fixtures\PreviewImportDefinition;
use SmartImport\Tests\TestCase;

class ImportPreviewServiceTest extends TestCase
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
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Storage::fake('local');
        Queue::fake();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('smart-import.importers', [PreviewImportDefinition::class]);
        Gate::define('create', fn (): bool => true);
        Gate::define('view', fn (): bool => true);
        Gate::define('update', fn (): bool => true);
        Gate::define('queue', fn (): bool => true);
        Gate::define('useImporter', fn (): bool => true);
        Gate::define('viewSensitiveImportFields', fn (): bool => false);
    }

    #[Test]
    public function preview_counts_create_update_and_fail_actions_and_redacts_sensitive_values(): void
    {
        $target = $this->target([
            'name' => 'Existing contact',
            'email' => 'existing@example.test',
            'api_token' => 'secret-before',
            'department' => 'Sales',
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage(
            "Name,Email,API token,Department\nUpdated contact,existing@example.test,secret-after,\nNew contact,new@example.test,secret-new,Marketing\n,bad-email,secret-invalid,\n",
            $actor,
        );
        $previewService = $this->app->make(ImportPreviewService::class);

        $summary = $previewService->generate((string) $session->getKey(), $actor);

        $this->assertSame([
            'staging_revision' => 1,
            'total' => 3,
            'create' => 1,
            'update' => 1,
            'skip' => 0,
            'fail' => 1,
        ], $summary);
        $page = $previewService->rows((string) $session->getKey(), $actor, perPage: 2);
        $this->assertSame(2, $page['last_page']);
        $this->assertSame(3, $page['total']);
        $update = $page['rows'][0]['preview'];
        $this->assertSame('update', $update['action']);
        $this->assertSame((string) $target->getKey(), $update['target_id']);
        $nameChange = collect($update['changes'])->firstWhere('field', 'name');
        $this->assertSame('Existing contact', $nameChange['before']);
        $this->assertSame('Updated contact', $nameChange['after']);
        $tokenChange = collect($update['changes'])->firstWhere('field', 'api_token');
        $this->assertSame('[redacted]', $tokenChange['before']);
        $this->assertSame('[redacted]', $tokenChange['after']);
        $this->assertTrue($tokenChange['redacted']);

        $create = $page['rows'][1]['preview'];
        $this->assertSame('create', $create['action']);
        $this->assertNull($create['target_id']);
        $createToken = collect($create['changes'])->firstWhere('field', 'api_token');
        $this->assertFalse($createToken['before_exists']);
        $this->assertSame('[redacted]', $createToken['after']);

        $storedPreview = (string) DB::table('smart_import_rows')
            ->where('import_id', $session->getKey())
            ->where('source_row_number', 2)
            ->value('preview');
        $this->assertStringNotContainsString('secret-before', $storedPreview);
        $this->assertStringNotContainsString('secret-after', $storedPreview);
        $this->assertSame('secret-before', $target->fresh()->api_token);
        $this->assertDatabaseCount('import_target_models', 1);
        $this->assertSame(ImportStatus::ReviewReady, $session->fresh()->status);
    }

    #[Test]
    public function archived_review_ready_imports_cannot_be_previewed_reviewed_or_confirmed(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nAda,ada@example.test,PRIVATE-TOKEN,Sales\n", $actor);
        app(FileIntakeService::class)->archive((string) $session->getKey(), $actor);
        $service = $this->app->make(ImportPreviewService::class);

        $this->assertDomainException(fn () => $service->generate((string) $session->getKey(), $actor), 'archived');
        $this->assertDomainException(fn () => $service->rows((string) $session->getKey(), $actor, allowMissingPreview: true), 'archived');
        $this->assertDomainException(
            fn () => $service->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision),
            'archived',
        );
    }

    #[Test]
    public function archived_terminal_imports_keep_read_only_history(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nAda,ada@example.test,PRIVATE-TOKEN,Sales\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);
        $session->forceFill(['status' => ImportStatus::Completed])->save();
        app(FileIntakeService::class)->archive((string) $session->getKey(), $actor);

        $row = $session->rows()->firstOrFail();
        $row->forceFill(['execution_status' => 'skipped'])->save();
        $history = $service->historyRow((string) $session->getKey(), (int) $row->getKey(), $actor);

        $this->assertSame((int) $row->getKey(), $history['id']);
        $this->assertSame('skipped', $history['execution_status']);
        $this->assertNull($history['execution_error']);
        $this->assertNull($history['outcome_target']['type']);
        $this->assertNull($history['outcome_target']['id']);
    }

    #[Test]
    public function rejected_review_ready_imports_cannot_be_previewed_or_confirmed(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nAda,ada@example.test,PRIVATE-TOKEN,Sales\n", $actor);
        app(FileIntakeService::class)->reject((string) $session->getKey(), 'Unreadable source', $actor);
        $service = $this->app->make(ImportPreviewService::class);

        $this->assertDomainException(fn () => $service->generate((string) $session->getKey(), $actor), 'rejected');
        $this->assertDomainException(fn () => $service->rows((string) $session->getKey(), $actor, allowMissingPreview: true), 'rejected');
        $this->assertDomainException(
            fn () => $service->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision),
            'rejected',
        );
    }

    #[Test]
    public function review_rows_preserve_mapped_field_order_and_importer_labels(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nAda,ada@example.test,PRIVATE-TOKEN,Sales\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);

        $page = $service->rows((string) $session->getKey(), $actor);
        $row = $page['rows'][0];

        $this->assertSame(['name', 'email', 'api_token', 'department'], array_keys($row['mapped_values']));
        $this->assertSame('[redacted]', $row['mapped_values']['api_token']);
        $this->assertSame('Name', $page['field_labels']['name']);
        $this->assertSame('API token', $page['field_labels']['api_token']);
        $this->assertSame('Department', $page['field_labels']['department']);

        Gate::define('viewSensitiveImportFields', fn ($user, $authorizedSession, $field): bool =>
            $authorizedSession->is($session)
            && $field->name === 'api_token'
        );

        $revealed = $service->rows((string) $session->getKey(), $actor)['rows'][0];

        $this->assertSame(['name', 'email', 'api_token', 'department'], array_keys($revealed['mapped_values']));
        $this->assertSame('PRIVATE-TOKEN', $revealed['mapped_values']['api_token']);
    }

    #[Test]
    public function sensitive_preview_values_are_revealed_only_with_the_field_gate(): void
    {
        $target = $this->target([
            'name' => 'Existing contact',
            'email' => 'existing@example.test',
            'api_token' => 'secret-before',
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nUpdated,existing@example.test,secret-after,\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);

        Gate::define('viewSensitiveImportFields', fn ($user, $authorizedSession, $field): bool =>
            $authorizedSession->is($session)
            && $field->name === 'api_token'
        );

        $page = $service->rows((string) $session->getKey(), $actor);
        $change = collect($page['rows'][0]['preview']['changes'])->firstWhere('field', 'api_token');

        $this->assertSame('secret-before', $change['before']);
        $this->assertSame('secret-after', $change['after']);
        $this->assertFalse($change['redacted']);
        $this->assertSame('secret-before', $target->fresh()->api_token);
    }

    #[Test]
    public function history_row_returns_safe_outcomes_and_only_reveals_sensitive_diffs_with_the_field_gate(): void
    {
        $target = $this->target([
            'name' => 'Existing contact',
            'email' => 'existing@example.test',
            'api_token' => 'PRIVATE-BEFORE-TOKEN',
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage(
            "Name,Email,API token,Department\nUpdated contact,existing@example.test,PRIVATE-AFTER-TOKEN,Sales\n",
            $actor,
        );
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);

        $row = $session->rows()->firstOrFail();
        $row->forceFill([
            'execution_status' => 'updated',
            'validation_errors' => [[
                'field' => 'api_token',
                'code' => 'invalid_value',
                'safe_message' => 'PRIVATE-VALIDATION-MESSAGE',
                'message' => 'PRIVATE-VALIDATION-MESSAGE',
            ]],
            'warnings' => [['field' => 'name', 'message' => 'PRIVATE-WARNING-MESSAGE']],
            'last_error_code' => 'write_failed',
            'last_error_message' => 'PRIVATE-LAST-ERROR-MESSAGE',
            'execution_history' => [[
                'attempt' => 1,
                'status' => 'updated',
                'target_type' => ImportTargetModel::class,
                'target_id' => (string) $target->getKey(),
                'error_code' => 'write_failed',
                'message' => 'PRIVATE-ATTEMPT-MESSAGE',
                'completed_at' => now()->toDateTimeString(),
            ]],
        ])->save();
        $session->forceFill(['status' => ImportStatus::Completed])->save();

        $denied = $service->historyRow((string) $session->getKey(), (int) $row->getKey(), $actor);
        $nameChange = collect($denied['changes'])->firstWhere('field', 'name');
        $tokenChange = collect($denied['changes'])->firstWhere('field', 'api_token');

        $this->assertSame('Existing contact', $nameChange['before']);
        $this->assertSame('Updated contact', $nameChange['after']);
        $this->assertSame('[redacted]', $tokenChange['before']);
        $this->assertSame('[redacted]', $tokenChange['after']);
        $this->assertTrue($tokenChange['redacted']);
        $this->assertSame((string) $target->getKey(), $denied['preview_target']['id']);
        $this->assertSame((string) $target->getKey(), $denied['outcome_target']['id']);
        $this->assertSame('write_failed', $denied['execution_error']['code']);
        $this->assertSame('This field did not pass the configured validation rules.', $denied['validation_errors'][0]['message']);
        $this->assertSame(1, $denied['warning_count']);

        $serialized = json_encode($denied, JSON_THROW_ON_ERROR);
        foreach ([
            'PRIVATE-BEFORE-TOKEN',
            'PRIVATE-AFTER-TOKEN',
            'PRIVATE-VALIDATION-MESSAGE',
            'PRIVATE-WARNING-MESSAGE',
            'PRIVATE-LAST-ERROR-MESSAGE',
            'PRIVATE-ATTEMPT-MESSAGE',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $serialized);
        }
        $this->assertArrayNotHasKey('raw_values', $denied);
        $this->assertArrayNotHasKey('mapped_values', $denied);
        $this->assertArrayNotHasKey('normalized_values', $denied);

        Gate::define('viewSensitiveImportFields', fn ($user, $authorizedSession, $field): bool =>
            $authorizedSession->is($session)
            && $field->name === 'api_token'
        );

        $allowed = $service->historyRow((string) $session->getKey(), (int) $row->getKey(), $actor);
        $authorizedTokenChange = collect($allowed['changes'])->firstWhere('field', 'api_token');
        $this->assertSame('PRIVATE-BEFORE-TOKEN', $authorizedTokenChange['before']);
        $this->assertSame('PRIVATE-AFTER-TOKEN', $authorizedTokenChange['after']);
        $this->assertFalse($authorizedTokenChange['redacted']);
    }

    #[Test]
    public function history_row_lookup_rejects_a_row_from_a_different_session(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $first = $this->stage("Name,Email,API token,Department\nFirst,first@example.test,first-secret,Sales\n", $actor);
        $second = $this->stage("Name,Email,API token,Department\nSecond,second@example.test,second-secret,Sales\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $first->getKey(), $actor);
        $service->generate((string) $second->getKey(), $actor);
        $first->forceFill(['status' => ImportStatus::Completed])->save();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->historyRow((string) $first->getKey(), (int) $second->rows()->firstOrFail()->getKey(), $actor);
    }

    #[Test]
    public function preview_rechecks_matches_that_changed_after_staging(): void
    {
        $target = $this->target(['name' => 'Existing contact', 'email' => 'existing@example.test']);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage(
            "Name,Email,API token,Department\nUpdated,existing@example.test,secret,\n",
            $actor,
        );
        $target->forceFill(['email' => 'renamed@example.test']);
        $target->save();

        $service = $this->app->make(ImportPreviewService::class);
        $summary = $service->generate((string) $session->getKey(), $actor);
        $page = $service->rows((string) $session->getKey(), $actor);

        $this->assertSame(1, $summary['create']);
        $this->assertSame(0, $summary['update']);
        $this->assertSame('create', $page['rows'][0]['preview']['action']);
        $this->assertNull($page['rows'][0]['preview']['target_id']);
        $this->assertSame('renamed@example.test', $target->fresh()->email);
    }

    #[Test]
    public function edits_invalidate_previews_and_rows_require_a_current_preview(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nNew contact,new@example.test,secret,\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);

        $service->generate((string) $session->getKey(), $actor);
        $this->app->make(StagedRowService::class)
            ->edit((string) $session->getKey(), $session->rows()->firstOrFail()->id, 'name', 'Corrected contact', $actor, (int) $session->staging_revision);

        $this->assertNull($session->rows()->firstOrFail()->fresh()->preview);
        try {
            $service->confirm((string) $session->getKey(), $actor, (int) $session->fresh()->staging_revision);
            $this->fail('A preview invalidated by an edit cannot be confirmed.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('complete preview', $exception->getMessage());
        }

        $this->expectException(\DomainException::class);
        $service->rows((string) $session->getKey(), $actor);
    }

    #[Test]
    public function sensitive_staged_cells_require_a_separate_edit_ability(): void
    {
        Gate::define('editSensitiveImportFields', static fn (): bool => false);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nAda,ada@example.test,PRIVATE-TOKEN,Sales\n", $actor);

        $this->expectException(AuthorizationException::class);
        app(StagedRowService::class)->edit(
            (string) $session->getKey(),
            $session->rows()->firstOrFail()->id,
            'api_token',
            'CHANGED-PRIVATE-TOKEN',
            $actor,
            (int) $session->staging_revision,
        );
    }

    #[Test]
    public function confirmation_freezes_the_previewed_revision_and_queued_rows_cannot_be_edited(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nNew contact,new@example.test,secret,\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);

        $queued = $service->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision);

        $this->assertSame(ImportStatus::Queued, $queued->status);
        $this->assertSame(1, $queued->confirmed_revision);
        $this->assertSame(1, $queued->execution_total);
        $this->assertSame(1, $queued->current_attempt);
        $this->assertSame(1, $queued->attempt_history[0]['attempt']);
        $this->assertNotNull($queued->queued_at);
        Queue::assertPushed(ExecuteImportChunkJob::class, fn (ExecuteImportChunkJob $job): bool =>
            $job->sessionId === (string) $queued->getKey()
            && $job->attemptId === 1
            && $job->confirmedRevision === $queued->confirmed_revision
            && count($job->rowIds) === 1
        );
        $this->assertSame(1, $service->rows((string) $session->getKey(), $actor)['total']);

        try {
            $this->app->make(StagedRowService::class)
                ->edit((string) $session->getKey(), $session->rows()->firstOrFail()->id, 'name', 'Changed', $actor, (int) $session->staging_revision);
            $this->fail('A queued import row cannot be edited.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('only while the import is ready', $exception->getMessage());
        }

        $lockedRow = $session->rows()->firstOrFail()->fresh();
        $lockedRow->forceFill(['normalized_values' => ['name' => 'Tampered']]);

        try {
            $lockedRow->save();
            $this->fail('Queued staged input cannot be changed directly through the model.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('frozen staged row input', $exception->getMessage());
        }

        $lockedSession = $queued->fresh();
        $lockedSession->forceFill(['mapping' => [1 => 'email']]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot change frozen import execution field');
        $lockedSession->save();
    }

    #[Test]
    public function confirmation_requires_a_complete_preview_and_the_queue_gate(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nNew contact,new@example.test,secret,\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);

        try {
            $service->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision);
            $this->fail('A session without preview data cannot be confirmed.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('complete preview', $exception->getMessage());
        }

        $service->generate((string) $session->getKey(), $actor);
        Gate::define('queue', fn (): bool => false);

        $this->expectException(AuthorizationException::class);
        $service->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision);
    }

    #[Test]
    public function confirmation_rechecks_importer_permission_after_preview_generation(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nNew contact,new@example.test,secret,Sales\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);
        Gate::define('useImporter', fn (): bool => false);

        try {
            $service->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision);
            $this->fail('Importer permission must be checked again at confirmation.');
        } catch (AuthorizationException) {
            $this->assertSame(ImportStatus::ReviewReady, $session->fresh()->status);
            Queue::assertNotPushed(ExecuteImportChunkJob::class);
        }
    }

    #[Test]
    public function invalid_staged_rows_block_confirmation_by_default(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\n,not-an-email,secret,Sales\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);
        $summary = $service->generate((string) $session->getKey(), $actor);

        $this->assertSame(1, $summary['fail']);

        try {
            $service->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision);
            $this->fail('Invalid staged rows must block confirmation by default.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('invalid', strtolower($exception->getMessage()));
            $this->assertSame(ImportStatus::ReviewReady, $session->fresh()->status);
            Queue::assertNotPushed(ExecuteImportChunkJob::class);
        }
    }

    #[Test]
    public function a_stale_review_revision_cannot_confirm_a_newer_preview(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nNew contact,new@example.test,secret,Sales\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);
        $reviewedRevision = $session->staging_revision;
        $row = $session->rows()->firstOrFail();
        $this->app->make(StagedRowService::class)
            ->edit((string) $session->getKey(), $row->id, 'name', 'Corrected contact', $actor, (int) $session->staging_revision);
        $service->generate((string) $session->getKey(), $actor);

        try {
            $service->confirm((string) $session->getKey(), $actor, $reviewedRevision);
            $this->fail('A stale browser review must not confirm a newer staging revision.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('changed after you reviewed it', $exception->getMessage());
            $this->assertSame(ImportStatus::ReviewReady, $session->fresh()->status);
        }
    }

    #[Test]
    public function preview_generation_requires_the_session_update_ability(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nNew contact,new@example.test,secret,Sales\n", $actor);
        Gate::define('update', fn (): bool => false);

        $this->expectException(AuthorizationException::class);
        $this->app->make(ImportPreviewService::class)->generate((string) $session->getKey(), $actor);
    }

    #[Test]
    public function importer_capability_allows_explicit_exclusion_of_invalid_rows(): void
    {
        config()->set('smart-import.importers', [InvalidRowExclusionImportDefinition::class]);
        $this->app->forgetInstance(ImporterRegistry::class);
        $this->app->forgetInstance(ImportCoordinator::class);
        $this->app->forgetInstance(FileIntakeService::class);
        $this->app->forgetInstance(ImportStagingService::class);
        $this->app->forgetInstance(ImportPreviewService::class);

        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage(
            "Name,Email,API token,Department\nValid contact,valid@example.test,token,Sales\n,invalid-email,token,Support\n",
            $actor,
            'preview-contacts-with-invalid-exclusion',
        );
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);

        $confirmed = $service->confirm((string) $session->getKey(), $actor, $session->staging_revision, excludeInvalidRows: true);
        $rows = $confirmed->rows()->orderBy('source_row_number')->get();

        $this->assertSame(1, $confirmed->execution_total);
        $this->assertSame(true, $confirmed->options['exclude_invalid_rows']);
        $this->assertSame(1, $confirmed->options['excluded_invalid_row_count']);
        $this->assertSame(['pending', 'excluded'], $rows->pluck('execution_status')->all());
        Queue::assertPushed(ExecuteImportChunkJob::class, fn (ExecuteImportChunkJob $job): bool =>
            count($job->rowIds) === 1 && $job->rowIds === [$rows[0]->id]
        );
    }

    #[Test]
    public function an_importer_cannot_exclude_every_invalid_row_into_an_empty_attempt(): void
    {
        config()->set('smart-import.importers', [InvalidRowExclusionImportDefinition::class]);
        $this->app->forgetInstance(ImporterRegistry::class);
        $this->app->forgetInstance(ImportCoordinator::class);
        $this->app->forgetInstance(FileIntakeService::class);
        $this->app->forgetInstance(ImportStagingService::class);
        $this->app->forgetInstance(ImportPreviewService::class);

        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\n,invalid-email,token,Support\n", $actor, 'preview-contacts-with-invalid-exclusion');
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No valid staged rows remain');
        $service->confirm((string) $session->getKey(), $actor, $session->staging_revision, excludeInvalidRows: true);
    }

    #[Test]
    public function confirmation_dispatches_only_bounded_row_id_chunks_after_freezing_the_snapshot(): void
    {
        config()->set('smart-import.execution.chunk_size', 2);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage(
            "Name,Email,API token,Department\nOne,one@example.test,one,Sales\nTwo,two@example.test,two,Sales\nThree,three@example.test,three,Sales\nFour,four@example.test,four,Sales\nFive,five@example.test,five,Sales\n",
            $actor,
        );
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);
        $queued = $service->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision);
        $jobs = Queue::pushed(ExecuteImportChunkJob::class)->all();
        $chunkSizes = array_map(static fn (ExecuteImportChunkJob $job): int => count($job->rowIds), $jobs);
        sort($chunkSizes);
        $allRowIds = array_merge(...array_map(static fn (ExecuteImportChunkJob $job): array => $job->rowIds, $jobs));

        $this->assertSame([1, 2, 2], $chunkSizes);
        $this->assertCount(5, array_unique($allRowIds));
        $this->assertSame(5, $queued->execution_total);
        $this->assertSame(ImportStatus::Queued, $queued->status);

        foreach ($jobs as $job) {
            $this->assertSame((string) $queued->getKey(), $job->sessionId);
            $this->assertSame(1, $job->attemptId);
            $this->assertSame($queued->confirmed_revision, $job->confirmedRevision);
        }
    }

    #[Test]
    public function parallel_chunks_keep_importing_until_all_rows_commit_then_emit_one_terminal_event(): void
    {
        config()->set('smart-import.execution.chunk_size', 2);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage(
            "Name,Email,API token,Department\nOne,one@example.test,one,Sales\nTwo,two@example.test,two,Sales\nThree,three@example.test,three,Sales\n",
            $actor,
        );
        $previews = $this->app->make(ImportPreviewService::class);
        $previews->generate((string) $session->getKey(), $actor);
        $queued = $previews->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision);
        $jobs = Queue::pushed(ExecuteImportChunkJob::class)->all();
        Event::fake([ImportFinished::class]);
        $execution = $this->app->make(ImportExecutionService::class);
        $lifecycle = $this->app->make(ImportExecutionLifecycle::class);

        $jobs[0]->handle($execution, $lifecycle);

        $running = $queued->fresh();
        $this->assertSame(ImportStatus::Importing, $running->status);
        $this->assertSame(2, $running->processed);
        $this->assertNotNull($running->started_at);
        Event::assertNotDispatched(ImportFinished::class);

        $jobs[1]->handle($execution, $lifecycle);

        $completed = $queued->fresh();
        $this->assertSame(ImportStatus::Completed, $completed->status);
        $this->assertSame(3, $completed->processed);
        $this->assertSame(3, $completed->created_count);
        $this->assertNotNull($completed->finished_at);
        Event::assertDispatchedTimes(ImportFinished::class, 1);
        Event::assertDispatched(ImportFinished::class, fn (ImportFinished $event): bool =>
            $event->sessionId === (string) $queued->getKey()
            && $event->status === ImportStatus::Completed
            && $event->processed === 3
        );

        $jobs[1]->handle($execution, $lifecycle);

        $this->assertSame(3, $queued->fresh()->processed);
        Event::assertDispatchedTimes(ImportFinished::class, 1);
    }

    #[Test]
    public function real_import_confirmation_rejects_the_synchronous_queue_driver(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nNew contact,new@example.test,secret,Sales\n", $actor);
        $service = $this->app->make(ImportPreviewService::class);
        $service->generate((string) $session->getKey(), $actor);
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.driver', 'sync');
        $this->app->detectEnvironment(static fn (): string => 'production');

        try {
            $service->confirm((string) $session->getKey(), $actor, (int) $session->staging_revision);
            $this->fail('A real asynchronous import cannot use Laravel sync queue.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('sync driver would block', $exception->getMessage());
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }

        $this->assertSame(ImportStatus::ReviewReady, $session->fresh()->status);
        Queue::assertNotPushed(ExecuteImportChunkJob::class);
    }

    #[Test]
    public function preview_counts_skip_existing_actions_without_writing_targets(): void
    {
        config()->set('smart-import.importers', [SkipPreviewImportDefinition::class]);
        $this->app->forgetInstance(ImporterRegistry::class);
        $this->app->forgetInstance(ImportCoordinator::class);
        $this->app->forgetInstance(FileIntakeService::class);
        $this->app->forgetInstance(ImportStagingService::class);
        $this->app->forgetInstance(ImportPreviewService::class);

        $existing = $this->target(['name' => 'Existing contact', 'email' => 'existing@example.test']);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage(
            "Name,Email,API token,Department\nChanged,existing@example.test,secret,\nNew,new@example.test,secret-new,Sales\n",
            $actor,
            'skip-preview-contacts',
        );
        $service = $this->app->make(ImportPreviewService::class);

        $summary = $service->generate((string) $session->getKey(), $actor);
        $page = $service->rows((string) $session->getKey(), $actor, perPage: 2);

        $this->assertSame(1, $summary['create']);
        $this->assertSame(0, $summary['update']);
        $this->assertSame(1, $summary['skip']);
        $this->assertSame(0, $summary['fail']);
        $this->assertSame('skip', $page['rows'][0]['preview']['action']);
        $this->assertSame('existing_record_skipped', $page['rows'][0]['preview']['reason']);
        $this->assertSame([], $page['rows'][0]['preview']['changes']);
        $this->assertSame('Existing contact', $existing->fresh()->name);
        $this->assertDatabaseCount('import_target_models', 1);
    }

    #[Test]
    public function boolean_field_with_database_one_is_previewed_as_unchanged_for_csv_one(): void
    {
        $target = $this->target([
            'name' => 'Existing contact',
            'email' => 'active@example.test',
            'is_active' => 1,
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage(
            "Name,Email,Active\nExisting contact,active@example.test,1\n",
            $actor,
            mapping: [1 => 'name', 2 => 'email', 3 => 'is_active'],
        );

        $service = app(ImportPreviewService::class);
        $summary = $service->generate((string) $session->getKey(), $actor);
        $row = $service->rows((string) $session->getKey(), $actor)['rows'][0];

        $this->assertSame(1, $summary['skip']);
        $this->assertSame(0, $summary['update']);
        $this->assertSame('unchanged', $row['preview']['reason']);
        $this->assertSame([], $row['preview']['changes']);
        $this->assertSame(1, $target->fresh()->getRawOriginal('is_active'));
    }

    #[Test]
    public function a_real_boolean_value_change_remains_an_update_in_preview(): void
    {
        $target = $this->target([
            'name' => 'Existing contact',
            'email' => 'active@example.test',
            'is_active' => 0,
        ]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage(
            "Name,Email,Active\nExisting contact,active@example.test,1\n",
            $actor,
            mapping: [1 => 'name', 2 => 'email', 3 => 'is_active'],
        );

        $service = app(ImportPreviewService::class);
        $summary = $service->generate((string) $session->getKey(), $actor);
        $row = $service->rows((string) $session->getKey(), $actor)['rows'][0];

        $this->assertSame(1, $summary['update']);
        $this->assertSame('update', $row['preview']['action']);
        $this->assertSame(0, $target->fresh()->getRawOriginal('is_active'));
    }

    #[Test]
    public function large_preview_is_queued_and_persists_progress_before_confirmation(): void
    {
        config()->set('smart-import.preview.async_threshold_rows', 1);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nAda,ada@example.test,secret,Sales\n", $actor);
        $service = app(ImportPreviewService::class);

        $result = $service->generateOrQueue((string) $session->getKey(), $actor, (int) $session->staging_revision);
        $building = $session->fresh();
        $job = Queue::pushed(GenerateImportPreviewJob::class)->first();

        $this->assertTrue($result['queued']);
        $this->assertFalse($result['already_queued']);
        $this->assertNull($result['summary']);
        $this->assertSame('building', $building->options['preview_state']);
        $this->assertSame(0, $building->options['preview_processed']);
        $this->assertSame(1, $building->options['preview_total']);
        $this->assertNotNull($job);
        $this->assertSame((string) $building->getKey(), $job->sessionId);
        $this->assertSame($building->mapping_revision, $job->mappingRevision);
        $this->assertSame($building->staging_revision, $job->stagingRevision);

        $job->handle($service);
        $ready = $session->fresh();
        $this->assertSame('ready', $ready->options['preview_state']);
        $this->assertSame(1, $ready->options['preview_processed']);
        $this->assertSame(1, $ready->options['preview_summary']['create']);
        $this->assertSame('create', $service->rows((string) $session->getKey(), $actor)['rows'][0]['preview']['action']);
        $this->assertSame(0, ImportTargetModel::query()->count(), 'Preview generation must not write target records.');
    }

    #[Test]
    public function repeated_large_preview_requests_share_the_current_durable_generation(): void
    {
        config()->set('smart-import.preview.async_threshold_rows', 1);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stage("Name,Email,API token,Department\nAda,ada@example.test,secret,Sales\n", $actor);
        $service = app(ImportPreviewService::class);

        $first = $service->generateOrQueue((string) $session->getKey(), $actor, (int) $session->staging_revision);
        $second = $service->generateOrQueue((string) $session->getKey(), $actor, (int) $session->staging_revision);

        $this->assertFalse($first['already_queued']);
        $this->assertTrue($second['already_queued']);
        Queue::assertPushed(GenerateImportPreviewJob::class, 1);
    }

    private function assertDomainException(callable $callback, string $messageFragment): void
    {
        try {
            $callback();
            $this->fail('The import action should have been refused for a blocked intake.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString($messageFragment, $exception->getMessage());
        }
    }

    private function stage(
        string $contents,
        GenericUser $actor,
        string $importerKey = 'preview-contacts',
        array $mapping = [1 => 'name', 2 => 'email', 3 => 'api_token', 4 => 'department'],
    ): \SmartImport\Models\ImportSession
    {
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

        return $staging->fresh();
    }

    /** @param array<string, mixed> $attributes */
    private function target(array $attributes): ImportTargetModel
    {
        $target = new ImportTargetModel();
        $target->forceFill($attributes);
        $target->save();

        return $target;
    }
}
