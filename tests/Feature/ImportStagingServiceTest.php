<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\ImportStatus;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Models\ImportRow;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImportPayloadCodec;
use SmartImport\Services\ImporterRegistry;
use SmartImport\Services\ImportStagingService;
use SmartImport\Services\StagedRowService;
use SmartImport\Tests\Fixtures\BlankValueImportDefinition;
use SmartImport\Tests\Fixtures\CompositeStagingImportDefinition;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\Fixtures\PreviewImportDefinition;
use SmartImport\Tests\Fixtures\StagingImportDefinition;
use SmartImport\Tests\TestCase;

class ImportStagingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('import_target_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('department')->nullable();
            $table->timestamps();
        });

        Storage::fake('local');
        Queue::fake();
        config()->set('smart-import.importers', [StagingImportDefinition::class]);
        Gate::define('create', fn (): bool => true);
        Gate::define('view', fn (): bool => true);
        Gate::define('update', fn (): bool => true);
        Gate::define('useImporter', fn (): bool => true);
    }

    #[Test]
    public function staging_freezes_configuration_queues_ids_and_persists_row_outcomes_and_counters(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email,Notes\n Ada ,ada@example.test,first\n,not-an-email,second\n", $actor);

        $staging = $this->staging()->start(
            (string) $session->getKey(),
            $actor,
            'staging-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email', 3 => null],
        );

        Queue::assertPushed(StageImportJob::class, fn (StageImportJob $job): bool =>
            $job->sessionId === (string) $session->getKey()
            && $job->mappingRevision === 1
            && $job->stagingRevision === 1
            && $job->queue === 'imports'
        );
        $this->assertSame(ImportStatus::Staging, $staging->status);
        $this->assertSame('staging-contacts', $staging->importer_key);
        $this->assertSame('1', $staging->importer_version);
        $this->assertSame('Worksheet', $staging->sheet_name);
        $this->assertSame([1 => 'name', 2 => 'email', 3 => null], $staging->mapping);
        $this->assertSame(1, $staging->mapping_revision);
        $this->assertSame(1, $staging->staging_revision);
        $this->assertSame(2, $staging->stage_total);

        (new StageImportJob((string) $session->getKey(), 1, 1))->handle($this->staging());

        $staged = $staging->fresh();
        $this->assertSame(ImportStatus::ReviewReady, $staged->status);
        $this->assertSame(2, $staged->stage_total);
        $this->assertSame(2, $staged->stage_processed);
        $this->assertSame(1, $staged->stage_valid);
        $this->assertSame(1, $staged->stage_invalid);
        $this->assertSame(0, $staged->stage_warning);
        $this->assertDatabaseCount('smart_import_rows', 2);

        $validRow = ImportRow::query()->where('source_row_number', 2)->firstOrFail();
        $invalidRow = ImportRow::query()->where('source_row_number', 3)->firstOrFail();
        $this->assertSame(1, $validRow->staging_revision);
        $this->assertSame(' Ada ', $validRow->raw_values[1]);
        $this->assertSame('Ada', $validRow->normalized_values['name']);
        $this->assertSame('valid', $validRow->validation_status);
        $this->assertSame('invalid', $invalidRow->validation_status);
        $this->assertSame(['name', 'email'], array_column($invalidRow->validation_errors, 'field'));
        $this->assertSame([3, 3], array_column($invalidRow->validation_errors, 'source_row'));
        $this->assertSame('pending', $validRow->execution_status);
    }

    #[Test]
    public function staging_uses_and_persists_the_same_csv_reader_settings_selected_for_preview(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name;Email\nAda;ada@example.test\n", $actor);

        $staging = $this->staging()->start(
            (string) $session->getKey(),
            $actor,
            'staging-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email'],
            ['csv_delimiter' => ';', 'csv_encoding' => 'UTF-8'],
        );

        $this->assertSame(';', $staging->options['reader']['csv_delimiter']);
        $this->assertSame('UTF-8', $staging->options['reader']['csv_encoding']);

        (new StageImportJob((string) $staging->getKey(), $staging->mapping_revision, $staging->staging_revision))
            ->handle($this->staging());

        $row = $staging->rows()->firstOrFail();
        $this->assertSame('Ada', $row->normalized_values['name']);
        $this->assertSame('ada@example.test', $row->normalized_values['email']);
        $this->assertSame(ImportStatus::ReviewReady, $staging->fresh()->status);
    }

    #[Test]
    public function sensitive_staged_cells_are_encrypted_while_safe_projections_can_read_them(): void
    {
        config()->set('smart-import.importers', [PreviewImportDefinition::class]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email,API token,Department\nAda,ada@example.test,PRIVATE-STAGED-TOKEN,Sales\n", $actor);
        $staging = $this->staging()->start(
            (string) $session->getKey(),
            $actor,
            'preview-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email', 3 => 'api_token', 4 => 'department'],
        );

        (new StageImportJob((string) $staging->getKey(), $staging->mapping_revision, $staging->staging_revision))
            ->handle($this->staging());

        $row = $staging->rows()->firstOrFail();
        $storedValues = DB::table('smart_import_rows')->where('id', $row->getKey())->first();
        $serialized = json_encode([
            $storedValues->raw_values,
            $storedValues->mapped_values,
            $storedValues->normalized_values,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('PRIVATE-STAGED-TOKEN', $serialized);
        $this->assertSame('PRIVATE-STAGED-TOKEN', app(ImportPayloadCodec::class)->reveal($row->raw_values)[3]);
        $this->assertSame('PRIVATE-STAGED-TOKEN', app(ImportPayloadCodec::class)->reveal($row->mapped_values)['api_token']);
        $this->assertSame('PRIVATE-STAGED-TOKEN', app(ImportPayloadCodec::class)->reveal($row->normalized_values)['api_token']);
        $this->assertSame(2, $staging->fresh()->options['security']['index_hash_version']);
        $this->assertSame(ImportStatus::ReviewReady, $staging->fresh()->status);
    }

    #[Test]
    public function staging_rejects_unapproved_reader_options_before_queueing(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email\nAda,ada@example.test\n", $actor);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Smart Import reader option');
        $this->staging()->start(
            (string) $session->getKey(),
            $actor,
            'staging-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email'],
            ['csv_delimiter' => ';', 'forged_target' => 'name'],
        );
    }

    #[Test]
    public function staging_rejects_the_sync_queue_before_changing_session_state(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email\nAda,ada@example.test\n", $actor);
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.driver', 'sync');
        $this->app->detectEnvironment(static fn (): string => 'production');

        try {
            $this->staging()->start(
                (string) $session->getKey(),
                $actor,
                'staging-contacts',
                0,
                1,
                [1 => 'name', 2 => 'email'],
            );
            $this->fail('Staging must reject an inline queue before transitioning the session.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('sync driver would block', $exception->getMessage());
            $this->assertNull($session->fresh()->status);
            Queue::assertNotPushed(StageImportJob::class);
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }
    }

    #[Test]
    public function ten_thousand_rows_are_staged_by_the_queued_worker_in_bounded_batches(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $contents = "Name,Email\n";

        for ($row = 1; $row <= 10_000; $row++) {
            $contents .= 'Contact '.$row.',contact'.$row.'@example.test'."\n";
        }

        $session = $this->upload($contents, $actor);
        $staging = $this->staging();
        $queued = $staging->start(
            (string) $session->getKey(),
            $actor,
            'staging-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email'],
        );

        $this->assertSame(ImportStatus::Staging, $queued->status);
        $this->assertSame(0, $queued->stage_processed);
        $this->assertSame(0, $queued->rows()->count());
        Queue::assertPushed(StageImportJob::class);

        (new StageImportJob((string) $session->getKey(), 1, 1))->handle($staging);

        $completed = $queued->fresh();
        $this->assertSame(ImportStatus::ReviewReady, $completed->status);
        $this->assertSame(10_000, $completed->stage_total);
        $this->assertSame(10_000, $completed->stage_processed);
        $this->assertSame(10_000, $completed->stage_valid);
        $this->assertSame(0, $completed->stage_invalid);
        $this->assertSame(10_000, $completed->rows()->count());
    }

    #[Test]
    public function staging_persists_new_matched_and_ambiguous_target_classifications(): void
    {
        $matchedTarget = $this->target(['name' => 'Existing Ada', 'email' => 'ada@example.test']);
        $this->target(['name' => 'Duplicate one', 'email' => 'duplicate@example.test']);
        $this->target(['name' => 'Duplicate two', 'email' => 'duplicate@example.test']);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stageRows(
            "Name,Email\nAda,ada@example.test\nGrace,grace@example.test\nA duplicate,duplicate@example.test\n",
            $actor,
        );
        $rows = $session->rows()->orderBy('source_row_number')->get();

        $this->assertSame('matched', $rows[0]->change_type);
        $this->assertSame(ImportTargetModel::class, $rows[0]->resolved_target_type);
        $this->assertSame((string) $matchedTarget->getKey(), $rows[0]->resolved_target_id);
        $this->assertSame('new', $rows[1]->change_type);
        $this->assertSame('ambiguous', $rows[2]->change_type);
        $this->assertNull($rows[2]->resolved_target_id);
        $this->assertSame('target_match_ambiguous', $rows[2]->validation_errors[0]['code']);
        $this->assertSame(2, $session->stage_valid);
        $this->assertSame(1, $session->stage_invalid);
        $this->assertDatabaseCount('import_target_models', 3);
    }

    #[Test]
    public function staging_preserves_blank_values_and_distinguishes_explicit_nulls(): void
    {
        config()->set('smart-import.importers', [BlankValueImportDefinition::class]);
        $this->app->forgetInstance(ImporterRegistry::class);
        $this->app->forgetInstance(ImportCoordinator::class);
        $this->app->forgetInstance(FileIntakeService::class);
        $this->app->forgetInstance(ImportStagingService::class);

        $this->target(['name' => 'Existing contact', 'email' => 'existing@example.test', 'department' => 'Sales']);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload(
            "Email,Nickname,Phone,Department,Note\nexisting@example.test,,,,[NULL]\n",
            $actor,
        );
        $staging = $this->staging()->start(
            (string) $session->getKey(),
            $actor,
            'blank-values',
            0,
            1,
            [1 => 'email', 2 => 'nickname', 3 => 'phone', 4 => 'department', 5 => 'note'],
        );

        (new StageImportJob((string) $session->getKey(), 1, 1))->handle($this->staging());

        $row = $staging->fresh()->rows()->firstOrFail();
        $this->assertSame('matched', $row->change_type);
        $this->assertArrayHasKey('nickname', $row->mapped_values);
        $this->assertContains($row->mapped_values['nickname'], ['', null]);
        $this->assertSame('[NULL]', $row->mapped_values['note']);
        $this->assertArrayNotHasKey('nickname', $row->normalized_values);
        $this->assertSame('', $row->normalized_values['phone']);
        $this->assertArrayHasKey('department', $row->normalized_values);
        $this->assertNull($row->normalized_values['department']);
        $this->assertArrayHasKey('note', $row->normalized_values);
        $this->assertNull($row->normalized_values['note']);
        $this->assertSame('valid', $row->validation_status);
    }

    #[Test]
    public function an_edited_unique_key_rechecks_its_existing_target_match(): void
    {
        $existingTarget = $this->target(['name' => 'Ada', 'email' => 'ada@example.test']);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stageRows("Name,Email\nGrace,grace@example.test\n", $actor);
        $row = $session->rows()->firstOrFail();
        $this->assertSame('new', $row->change_type);

        $edited = $this->stagedRows()->edit((string) $session->getKey(), $row->id, 'email', 'ada@example.test', $actor, (int) $session->staging_revision);

        $this->assertSame('matched', $edited->change_type);
        $this->assertSame((string) $existingTarget->getKey(), $edited->resolved_target_id);
        $this->assertSame(1, $session->fresh()->stage_valid);
    }

    #[Test]
    public function duplicate_job_delivery_does_not_increment_staging_counters_twice(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email\nAda,ada@example.test\n", $actor);
        $staging = $this->staging()->start((string) $session->getKey(), $actor, 'staging-contacts', 0, 1, [1 => 'name', 2 => 'email']);
        $job = new StageImportJob((string) $session->getKey(), 1, 1);

        $job->handle($this->staging());
        $job->handle($this->staging());

        $fresh = $staging->fresh();
        $this->assertSame(1, $fresh->stage_processed);
        $this->assertSame(1, $fresh->stage_valid);
        $this->assertSame(1, $fresh->rows()->count());
        $this->assertSame(1, $fresh->staging_revision);
    }

    #[Test]
    public function a_new_mapping_revision_resets_old_staged_rows_and_stale_jobs_cannot_overwrite_it(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email,Alternate\nAda,old@example.test,new@example.test\n", $actor);
        $staging = $this->staging();
        $first = $staging->start((string) $session->getKey(), $actor, 'staging-contacts', 0, 1, [1 => 'name', 2 => 'email', 3 => null]);
        $staleJob = new StageImportJob((string) $session->getKey(), 1, 1);
        $staleJob->handle($staging);

        $second = $staging->start((string) $session->getKey(), $actor, 'staging-contacts', 0, 1, [1 => 'name', 2 => null, 3 => 'email']);
        $this->assertSame(2, $second->mapping_revision);
        $this->assertSame(2, $second->staging_revision);
        $this->assertSame(0, $second->stage_processed);
        $this->assertSame(0, $second->rows()->count());

        $staleJob->handle($staging);
        $this->assertSame(0, $second->fresh()->stage_processed);
        $this->assertSame(0, $second->fresh()->rows()->count());

        (new StageImportJob((string) $session->getKey(), 2, 2))->handle($staging);
        $newRow = $second->fresh()->rows()->firstOrFail();
        $this->assertSame('new@example.test', $newRow->normalized_values['email']);
        $this->assertSame(1, $second->fresh()->stage_processed);
    }

    #[Test]
    public function a_required_field_must_be_mapped_before_staging_is_queued(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email\nAda,ada@example.test\n", $actor);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Required importer field [name] must be mapped');

        $this->staging()->start((string) $session->getKey(), $actor, 'staging-contacts', 0, 1, [1 => null, 2 => 'email']);
    }

    #[Test]
    public function staging_requires_authorized_session_access_and_an_unstaged_state(): void
    {
        $owner = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Email\nAda,ada@example.test\n", $owner);

        Gate::define('view', fn (): bool => false);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->staging()->start((string) $session->getKey(), new GenericUser(['id' => 8]), 'staging-contacts', 0, 1, [1 => 'name', 2 => 'email']);
    }

    #[Test]
    public function duplicate_composite_key_values_are_reported_on_each_row_and_counted_as_invalid(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stageRows("Name,Email\nAda,shared@example.test\nGrace,shared@example.test\n", $actor);
        $rows = $session->rows()->orderBy('source_row_number')->get();

        $this->assertSame(0, $session->stage_valid);
        $this->assertSame(2, $session->stage_invalid);
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]->match_key_hash, $rows[1]->match_key_hash);
        $this->assertSame(['duplicate_in_file'], array_column($rows[0]->validation_errors, 'code'));
        $this->assertSame(['email'], array_column($rows[1]->validation_errors, 'field'));
        $this->assertSame([2, 3], array_column(array_merge(...array_map(
            static fn ($row): array => $row->validation_errors,
            $rows->all(),
        )), 'source_row'));
    }

    #[Test]
    public function a_composite_unique_key_compares_each_configured_field(): void
    {
        config()->set('smart-import.importers', [CompositeStagingImportDefinition::class]);
        $actor = new GenericUser(['id' => 7]);
        $session = $this->upload("Name,Department\nAda,Engineering\nAda,Engineering\nGrace,Engineering\n", $actor);
        $staging = $this->staging()->start(
            (string) $session->getKey(),
            $actor,
            'composite-staging',
            0,
            1,
            [1 => 'name', 2 => 'department'],
        );

        (new StageImportJob((string) $session->getKey(), 1, 1))->handle($this->staging());

        $rows = $staging->fresh()->rows()->orderBy('source_row_number')->get();
        $this->assertSame(['invalid', 'invalid', 'valid'], $rows->pluck('validation_status')->all());
        $this->assertSame($rows[0]->match_key_hash, $rows[1]->match_key_hash);
        $this->assertNotSame($rows[0]->match_key_hash, $rows[2]->match_key_hash);
        $this->assertSame(2, $staging->fresh()->stage_invalid);
        $this->assertSame(1, $staging->fresh()->stage_valid);
    }

    #[Test]
    public function editing_a_mapped_cell_revalidates_it_and_rechecks_both_old_and_new_duplicate_groups(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stageRows("Name,Email\nAda,ada@example.test\nGrace,grace@example.test\n", $actor);
        $rows = $session->rows()->orderBy('source_row_number')->get();
        $rawValues = $rows[0]->raw_values;

        $edited = $this->stagedRows()->edit((string) $session->getKey(), $rows[0]->id, 'email', 'grace@example.test', $actor, (int) $session->staging_revision);

        $this->assertSame('invalid', $edited->validation_status);
        $this->assertContains('duplicate_in_file', array_column($edited->validation_errors, 'code'));
        $this->assertSame($rawValues, $edited->raw_values);
        $this->assertSame(0, $session->fresh()->stage_valid);
        $this->assertSame(2, $session->fresh()->stage_invalid);
        $this->assertSame(2, $session->fresh()->staging_revision);
        $this->assertCount(2, $this->stagedRows()->rows((string) $session->getKey(), $actor, 'invalid'));
        $this->assertCount(0, $this->stagedRows()->rows((string) $session->getKey(), $actor, 'valid'));

        $edited = $this->stagedRows()->edit((string) $session->getKey(), $rows[0]->id, 'email', 'ada@example.test', $actor, (int) $session->fresh()->staging_revision);

        $this->assertSame('valid', $edited->validation_status);
        $this->assertSame([], $edited->validation_errors);
        $this->assertSame(2, $session->fresh()->stage_valid);
        $this->assertSame(0, $session->fresh()->stage_invalid);
        $this->assertSame(3, $session->fresh()->staging_revision);
        $this->assertCount(2, $this->stagedRows()->rows((string) $session->getKey(), $actor, 'valid'));
        $this->assertCount(0, $this->stagedRows()->rows((string) $session->getKey(), $actor, 'invalid'));
    }

    #[Test]
    public function editing_a_value_runs_laravel_validation_and_updates_valid_invalid_counters(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stageRows("Name,Email\nAda,ada@example.test\n", $actor);
        $row = $session->rows()->firstOrFail();
        $rawValues = $row->raw_values;

        $edited = $this->stagedRows()->edit((string) $session->getKey(), $row->id, 'email', 'invalid-address', $actor, (int) $session->staging_revision);

        $this->assertSame('invalid', $edited->validation_status);
        $this->assertSame('email', $edited->validation_errors[0]['code']);
        $this->assertSame(0, $session->fresh()->stage_valid);
        $this->assertSame(1, $session->fresh()->stage_invalid);
        $this->assertSame($rawValues, $edited->raw_values);
        $this->assertSame('invalid-address', $edited->mapped_values['email']);
    }

    #[Test]
    public function row_status_filter_rejects_unknown_values(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->stageRows("Name,Email\nAda,ada@example.test\n", $actor);

        $this->expectException(\InvalidArgumentException::class);
        $this->stagedRows()->rows((string) $session->getKey(), $actor, 'failed');
    }

    private function upload(string $contents, GenericUser $actor): \SmartImport\Models\ImportSession
    {
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $contents);

        return $this->app->make(FileIntakeService::class)->upload($file, $actor);
    }

    private function staging(): ImportStagingService
    {
        return $this->app->make(ImportStagingService::class);
    }

    private function stagedRows(): StagedRowService
    {
        return $this->app->make(StagedRowService::class);
    }

    private function stageRows(string $contents, GenericUser $actor): \SmartImport\Models\ImportSession
    {
        $session = $this->upload($contents, $actor);
        $staging = $this->staging()->start((string) $session->getKey(), $actor, 'staging-contacts', 0, 1, [1 => 'name', 2 => 'email']);

        (new StageImportJob((string) $session->getKey(), 1, 1))->handle($this->staging());

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
