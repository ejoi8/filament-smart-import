<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\ImportStatus;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Models\ImportAuditEntry;
use SmartImport\Models\ImportOutboxEvent;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportAuditService;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImportExecutionLifecycle;
use SmartImport\Services\ImportPreviewService;
use SmartImport\Services\ImportRetentionService;
use SmartImport\Services\ImportStagingService;
use SmartImport\Services\StagedRowService;
use SmartImport\Tests\Fixtures\PreviewImportDefinition;
use SmartImport\Tests\TestCase;

class ImportAuditAndRetentionTest extends TestCase
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

        foreach (['create', 'view', 'viewAny', 'update', 'queue', 'cancel', 'useImporter', 'manageRetention'] as $ability) {
            Gate::define($ability, static fn (): bool => true);
        }
    }

    #[Test]
    public function the_import_journey_records_atomic_bounded_audit_events_without_cell_values(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->reviewReadySession($actor, "Ada,ada@example.test,DO-NOT-AUDIT,Sales\n");
        app(ImportPreviewService::class)->generate((string) $session->getKey(), $actor);
        $confirmed = app(ImportPreviewService::class)->confirm((string) $session->getKey(), $actor, $session->staging_revision);
        app(ImportExecutionLifecycle::class)->requestCancellation((string) $confirmed->getKey(), $actor);

        $events = ImportAuditEntry::query()
            ->where('import_id', $session->getKey())
            ->orderBy('id')
            ->get();
        $eventNames = $events->pluck('event')->all();

        $this->assertContains('intake.registered', $eventNames);
        $this->assertContains('importer.assigned', $eventNames);
        $this->assertContains('configuration.saved', $eventNames);
        $this->assertContains('staging.completed', $eventNames);
        $this->assertContains('preview.generated', $eventNames);
        $this->assertContains('import.confirmed', $eventNames);
        $this->assertContains('import.cancellation_requested', $eventNames);
        $this->assertContains('import.cancelled', $eventNames);
        $this->assertSame(GenericUser::class, $events->firstWhere('event', 'intake.registered')->actor_type);
        $this->assertStringNotContainsString('DO-NOT-AUDIT', json_encode($events->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($session->storage_path, json_encode($events->toArray(), JSON_THROW_ON_ERROR));

        $latest = app(ImportAuditService::class)->latestFor((string) $session->getKey(), $actor);
        $this->assertCount($events->count(), $latest);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(ImportAuditService::class)->latestFor((string) $session->getKey(), new GenericUser(['id' => 8]));
    }

    #[Test]
    public function row_correction_audit_records_only_row_and_revision_metadata(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = $this->reviewReadySession($actor, "Ada,ada@example.test,PRIVATE-CELL,Sales\n");
        $row = $session->rows()->firstOrFail();

        app(StagedRowService::class)->edit(
            (string) $session->getKey(),
            (int) $row->getKey(),
            'name',
            'Corrected Ada',
            $actor,
            (int) $session->staging_revision,
        );

        $entry = ImportAuditEntry::query()->where('event', 'row.corrected')->firstOrFail();
        $this->assertSame(['row_number' => 2, 'staging_revision' => 2, 'field_count' => 1], $entry->metadata);
        $this->assertStringNotContainsString('Corrected Ada', json_encode($entry->metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('PRIVATE-CELL', json_encode($entry->metadata, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function retention_dry_run_lists_exact_objects_and_protects_active_retryable_delivery_and_held_work(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $candidate = $this->oldSession($actor, 'candidate.csv', ImportStatus::Completed);
        ImportRow::forceCreate([
            'import_id' => $candidate->getKey(),
            'source_row_number' => 2,
            'raw_values' => ['Name' => 'Private cell'],
            'execution_status' => 'created',
        ]);

        $held = $this->oldSession($actor, 'held.csv', ImportStatus::Completed);
        app(ImportRetentionService::class)->placeHold((string) $held->getKey(), $actor, 'Legal review reference');

        $retryable = $this->oldSession($actor, 'retryable.csv', ImportStatus::Completed, failedCount: 1);
        ImportRow::forceCreate([
            'import_id' => $retryable->getKey(),
            'source_row_number' => 2,
            'execution_status' => 'failed',
            'last_error_code' => 'execution_failed',
        ]);

        $delivery = $this->oldSession($actor, 'delivery.csv', ImportStatus::Completed);
        ImportOutboxEvent::forceCreate([
            'import_id' => $delivery->getKey(),
            'terminal_status' => 'completed',
            'event_key' => hash('sha256', 'retention-pending-event'),
            'payload_version' => 1,
            'payload' => ['status' => 'completed'],
            'delivery_status' => 'pending',
            'available_at' => now()->subDay(),
        ]);

        $active = $this->oldSession($actor, 'active.csv', ImportStatus::Importing);
        $before = ImportSession::query()->count();
        $existingFiles = Storage::disk('local')->allFiles('smart-import');
        $report = app(ImportRetentionService::class)->inspect(365, 100);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(2, $report['eligible_object_count']);
        $this->assertEqualsCanonicalizing(['source_file', 'staged_rows'], array_column($report['eligible_objects'], 'object'));
        $this->assertContains('manual_retention_hold', array_column($report['protected_imports'], 'reason'));
        $this->assertContains('failed_rows_may_be_retried', array_column($report['protected_imports'], 'reason'));
        $this->assertContains('notification_delivery_unsettled', array_column($report['protected_imports'], 'reason'));
        $this->assertContains('active_or_unfinished_import', array_column($report['protected_imports'], 'reason'));
        $this->assertSame($before, ImportSession::query()->count());
        $this->assertSame($existingFiles, Storage::disk('local')->allFiles('smart-import'));
        $this->assertNotSame((string) $active->getKey(), $report['eligible_objects'][0]['session_id']);
        $this->assertStringNotContainsString('storage_path', json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertSame('retained', $report['session_summaries']);
        $this->assertSame('retained', $report['audit_history']);
    }

    #[Test]
    public function an_empty_retention_scan_finishes_without_a_continuation_cursor(): void
    {
        $report = app(ImportRetentionService::class)->inspect(365, 10);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(0, $report['scanned_imports']);
        $this->assertFalse($report['has_more']);
        $this->assertArrayNotHasKey('next_cursor', $report);
    }

    #[Test]
    public function retention_cursor_walks_ties_and_continues_after_protected_sessions_without_mutating_data(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $sameTimestamp = now()->subDays(500)->format('Y-m-d H:i:s');
        $sessions = [];

        for ($index = 0; $index < 7; $index++) {
            $session = $this->oldSession($actor, "page-{$index}.csv", ImportStatus::Completed);
            DB::table('smart_imports')->where('id', $session->getKey())->update(['created_at' => $sameTimestamp]);
            $sessions[] = $session->fresh();
        }

        usort($sessions, static fn (ImportSession $left, ImportSession $right): int => strcmp(
            (string) $left->getKey(),
            (string) $right->getKey(),
        ));
        $heldId = (string) $sessions[0]->getKey();
        app(ImportRetentionService::class)->placeHold($heldId, $actor, 'Retain first page item');
        $sessionCount = ImportSession::query()->count();
        $rowCount = ImportRow::query()->count();
        $outboxCount = ImportOutboxEvent::query()->count();
        $auditCount = ImportAuditEntry::query()->count();
        $fileList = Storage::disk('local')->allFiles('smart-import');
        $visited = [];
        $cursor = null;
        $pageNumber = 0;

        do {
            $limit = $pageNumber === 1 ? 3 : 2;
            $report = app(ImportRetentionService::class)->inspect(365, $limit, $cursor);
            $pageIds = array_merge(
                array_column($report['eligible_objects'], 'session_id'),
                array_column($report['protected_imports'], 'session_id'),
            );
            sort($pageIds, SORT_STRING);
            $this->assertCount($report['scanned_imports'], $pageIds);
            $visited = array_merge($visited, $pageIds);

            if ($report['has_more']) {
                $this->assertNotEmpty($report['next_cursor']);
                $cursor = $report['next_cursor'];
            } else {
                $this->assertArrayNotHasKey('next_cursor', $report);
                $cursor = null;
            }

            if ($pageNumber === 0) {
                $this->assertSame([$heldId], array_column($report['protected_imports'], 'session_id'));
                $this->assertSame($heldId, $pageIds[0]);
            }

            $pageNumber++;
            $this->assertLessThan(6, $pageNumber, 'Retention pagination must finish within the bounded fixture.');
        } while ($cursor !== null);

        $expectedIds = array_map(static fn (ImportSession $session): string => (string) $session->getKey(), $sessions);
        $this->assertSame($expectedIds, $visited);
        $this->assertSame($sessionCount, ImportSession::query()->count());
        $this->assertSame($rowCount, ImportRow::query()->count());
        $this->assertSame($outboxCount, ImportOutboxEvent::query()->count());
        $this->assertSame($auditCount, ImportAuditEntry::query()->count());
        $this->assertSame($fileList, Storage::disk('local')->allFiles('smart-import'));
    }

    #[Test]
    public function retention_cursor_keeps_its_cutoff_and_scan_boundary_when_time_advances(): void
    {
        $start = Carbon::parse('2026-11-21 12:00:00');
        Carbon::setTestNow($start);

        try {
            $actor = new GenericUser(['id' => 7]);
            $older = $this->oldSession($actor, 'older-at-boundary.csv', ImportStatus::Completed);
            DB::table('smart_imports')->where('id', $older->getKey())->update([
                'created_at' => $start->copy()->subDays(400)->format('Y-m-d H:i:s'),
            ]);
            $cutoffEdge = $this->oldSession($actor, 'cutoff-edge.csv', ImportStatus::Completed);
            DB::table('smart_imports')->where('id', $cutoffEdge->getKey())->update([
                'created_at' => $start->copy()->subDays(365)->format('Y-m-d H:i:s'),
            ]);

            $service = app(ImportRetentionService::class);
            $first = $service->inspect(365, 1);
            $this->assertTrue($first['has_more']);
            $cursor = $first['next_cursor'];
            Carbon::setTestNow($start->copy()->addDays(5));

            $newBackdated = $this->oldSession($actor, 'added-during-scan.csv', ImportStatus::Completed);
            DB::table('smart_imports')->where('id', $newBackdated->getKey())->update([
                'created_at' => $start->copy()->subDays(362)->format('Y-m-d H:i:s'),
            ]);

            $second = $service->inspect(365, 10, $cursor);
            $scanned = array_merge(
                array_column($second['eligible_objects'], 'session_id'),
                array_column($second['protected_imports'], 'session_id'),
            );

            $this->assertSame($first['cutoff'], $second['cutoff']);
            $this->assertSame([(string) $cutoffEdge->getKey()], $scanned);
            $this->assertNotContains((string) $newBackdated->getKey(), $scanned);
            $this->assertFalse($second['has_more']);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function invalid_retention_cursors_fail_closed_and_cli_can_continue_a_scan(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $this->oldSession($actor, 'cli-one.csv', ImportStatus::Completed);
        $this->oldSession($actor, 'cli-two.csv', ImportStatus::Completed);
        $service = app(ImportRetentionService::class);
        $first = $service->inspect(365, 1);
        $cursor = $first['next_cursor'];

        $this->assertNotEmpty($cursor);
        $this->assertInvalidCursor(static fn () => $service->inspect(365, 1, 'tampered-cursor'));
        $this->assertInvalidCursor(static fn () => $service->inspect(365, 1, str_repeat('x', 4097)));
        $this->assertInvalidCursor(static fn () => $service->inspect(364, 1, $cursor));
        $this->assertInvalidCursor(static fn () => $service->inspect(365, 1, Crypt::encryptString(json_encode([
            'version' => 2,
            'older_than_days' => 365,
            'cutoff' => now()->subDays(365)->format('Y-m-d H:i:s'),
            'issued_at' => now()->timestamp,
            'upper' => ['created_at' => now()->subDays(400)->format('Y-m-d H:i:s'), 'id' => '00000000-0000-4000-8000-000000000001'],
            'after' => ['created_at' => now()->subDays(400)->format('Y-m-d H:i:s'), 'id' => '00000000-0000-4000-8000-000000000000'],
        ], JSON_THROW_ON_ERROR))));

        $issuedAt = now();
        Carbon::setTestNow($issuedAt);
        $expiringCursor = $service->inspect(365, 1)['next_cursor'];
        Carbon::setTestNow($issuedAt->copy()->addDays(8));
        $this->assertInvalidCursor(static fn () => $service->inspect(365, 1, $expiringCursor));
        Carbon::setTestNow();

        $cliFirstExit = Artisan::call('smart-import:retention-dry-run', [
            '--older-than' => 365,
            '--limit' => 1,
        ]);
        $cliFirst = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $cliSecondExit = Artisan::call('smart-import:retention-dry-run', [
            '--older-than' => 365,
            '--limit' => 1,
            '--cursor' => $cliFirst['next_cursor'],
        ]);
        $cliSecond = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $cliFirstExit);
        $this->assertTrue($cliFirst['has_more']);
        $this->assertSame(0, $cliSecondExit);
        $this->assertFalse($cliSecond['has_more']);
        $this->assertNotSame(
            $cliFirst['eligible_objects'][0]['session_id'],
            $cliSecond['eligible_objects'][0]['session_id'],
        );

        $invalidExit = Artisan::call('smart-import:retention-dry-run', [
            '--older-than' => 365,
            '--limit' => 1,
            '--cursor' => 'tampered-cursor',
        ]);
        $this->assertSame(2, $invalidExit);
        $this->assertStringContainsString('invalid or expired', Artisan::output());
    }

    #[Test]
    public function audit_records_roll_back_with_the_operation_and_reject_private_metadata(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $session = app(FileIntakeService::class)->upload(
            UploadedFile::fake()->createWithContent('contacts.csv', "Name,Email\nAda,ada@example.test\n"),
            $actor,
        );
        $audit = app(ImportAuditService::class);

        $before = ImportAuditEntry::query()->count();

        try {
            DB::transaction(function () use ($audit, $session): void {
                $audit->record($session, 'import.failed');
                throw new \RuntimeException('rollback the operation');
            });
        } catch (\RuntimeException) {
            // The operation and its audit row share the same transaction.
        }

        $this->assertSame($before, ImportAuditEntry::query()->count());

        $this->expectException(\InvalidArgumentException::class);
        DB::transaction(fn () => $audit->record($session, 'import.failed', metadata: ['cell_value' => 'private']));
    }

    private function reviewReadySession(GenericUser $actor, string $rows): ImportSession
    {
        $session = app(FileIntakeService::class)->upload(
            UploadedFile::fake()->createWithContent('contacts.csv', "Name,Email,API token,Department\n".$rows),
            $actor,
        );
        app(ImportCoordinator::class)->assignImporter((string) $session->getKey(), 'preview-contacts', $actor);
        $session = app(ImportStagingService::class)->start(
            (string) $session->getKey(),
            $actor,
            'preview-contacts',
            0,
            1,
            [1 => 'name', 2 => 'email', 3 => 'api_token', 4 => 'department'],
        );
        (new StageImportJob((string) $session->getKey(), 1, 1))->handle(app(ImportStagingService::class));

        return $session->fresh();
    }

    private function assertInvalidCursor(callable $inspect): void
    {
        try {
            $inspect();
            $this->fail('An invalid retention cursor should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('cursor', strtolower($exception->getMessage()));
        }
    }

    private function oldSession(GenericUser $actor, string $filename, ImportStatus $status, int $failedCount = 0): ImportSession
    {
        $session = app(FileIntakeService::class)->upload(
            UploadedFile::fake()->createWithContent($filename, "Name,Email\n{$filename},{$filename}@example.test\n"),
            $actor,
        );
        $old = now()->subYears(2);
        $session->forceFill([
            'status' => $status,
            'finished_at' => $status === ImportStatus::Importing ? null : $old,
            'execution_total' => $failedCount > 0 ? 1 : 0,
            'processed' => $failedCount > 0 ? 1 : 0,
            'failed_count' => $failedCount,
            'created_at' => $old,
            'updated_at' => $old,
        ])->save();

        return $session->fresh();
    }
}
