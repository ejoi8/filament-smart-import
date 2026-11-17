<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImportProgressService;
use SmartImport\Tests\TestCase;

class ImportProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('create', static fn (): bool => true);
        Gate::define('view', static fn (): bool => true);
        Gate::define('cancel', static fn (): bool => true);
    }

    #[Test]
    public function returns_durable_counts_and_only_redacted_row_errors(): void
    {
        $actor = new GenericUser(['id' => 51]);
        $session = $this->sessionFor($actor);
        $session->forceFill([
            'status' => ImportStatus::Importing,
            'current_attempt' => 2,
            'stage_total' => 8,
            'stage_processed' => 8,
            'stage_valid' => 7,
            'stage_invalid' => 1,
            'stage_warning' => 2,
            'execution_total' => 4,
            'processed' => 1,
            'created_count' => 1,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
        ])->save();
        ImportRow::query()->forceCreate([
            'import_id' => $session->getKey(),
            'source_row_number' => 19,
            'raw_values' => ['secret' => 'PRIVATE-SPREADSHEET-VALUE'],
            'execution_status' => 'failed',
            'last_error_code' => 'validation_failed',
            'last_error_message' => 'PRIVATE-SPREADSHEET-VALUE',
        ]);

        $progress = app(ImportProgressService::class)->get((string) $session->getKey(), $actor);

        $this->assertSame('importing', $progress['status']);
        $this->assertSame(2, $progress['attempt']);
        $this->assertSame(['percentage' => 25, 'processed' => 1, 'total' => 4, 'remaining' => 3, 'created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0], $progress['execution']);
        $this->assertSame(['status' => 'importing', 'processed' => 8, 'total' => 8, 'valid' => 7, 'invalid' => 1, 'warnings' => 2], $progress['stage']);
        $this->assertSame([[
            'row' => 19,
            'code' => 'validation_failed',
            'message' => 'The row could not be processed under the confirmed import rules.',
        ]], $progress['recent_errors']);
        $this->assertStringNotContainsString('PRIVATE-SPREADSHEET-VALUE', serialize($progress));

        $session->forceFill(['processed' => 4, 'created_count' => 3, 'failed_count' => 1, 'status' => ImportStatus::Completed])->save();
        $updated = app(ImportProgressService::class)->get((string) $session->getKey(), $actor);

        $this->assertSame(100, $updated['execution']['percentage']);
        $this->assertSame(0, $updated['execution']['remaining']);
        $this->assertSame('completed', $updated['status']);
    }

    #[Test]
    public function session_scope_and_view_authorization_are_applied_on_every_read(): void
    {
        $owner = new GenericUser(['id' => 51]);
        $other = new GenericUser(['id' => 52]);
        $session = $this->sessionFor($owner);

        try {
            app(ImportProgressService::class)->get((string) $session->getKey(), $other);
            $this->fail('A different actor must not read the import progress.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertTrue(true);
        }

        Gate::define('view', static fn (): bool => false);

        $this->expectException(AuthorizationException::class);
        app(ImportProgressService::class)->get((string) $session->getKey(), $owner);
    }

    private function sessionFor(GenericUser $actor): ImportSession
    {
        return app(ImportCoordinator::class)->create([
            'source' => 'cli',
            'original_filename' => 'progress.csv',
            'storage_disk' => 'local',
            'storage_path' => 'smart-import/progress/'.str()->uuid().'.csv',
            'file_hash' => hash('sha256', (string) str()->uuid()),
            'file_size' => 32,
            'file_type' => 'csv',
        ], $actor);
    }
}
