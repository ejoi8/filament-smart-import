<?php

namespace SmartImport\Tests\Feature;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Models\ImportPreset;
use SmartImport\Models\ImportAuditEntry;
use SmartImport\Models\ImportNotification;
use SmartImport\Models\ImportOutboxEvent;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use SmartImport\Tests\TestCase;

class ImportSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function package_tables_and_expected_columns_are_migrated(): void
    {
        $this->assertTrue(Schema::hasTable('smart_imports'));
        $this->assertTrue(Schema::hasTable('smart_import_rows'));
        $this->assertTrue(Schema::hasTable('smart_import_presets'));
        $this->assertTrue(Schema::hasTable('smart_import_outbox'));
        $this->assertTrue(Schema::hasTable('smart_import_notifications'));
        $this->assertTrue(Schema::hasTable('smart_import_audit_entries'));
        $this->assertTrue(Schema::hasColumns('smart_imports', [
            'intake_status',
            'claimed_storage_path',
            'possible_duplicate_of',
            'status',
            'stage_processed',
            'execution_total',
            'attempt_history',
            'retention_hold_at',
            'retention_hold_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('smart_import_rows', [
            'validation_status',
            'execution_status',
            'attempt_count',
            'execution_history',
            'staging_revision',
        ]));
        $this->assertTrue(Schema::hasColumns('smart_import_presets', [
            'importer_version',
            'scope_key',
            'source_fingerprint',
            'mapping',
            'options',
            'created_by_type',
            'created_by_id',
        ]));
        $this->assertTrue(Schema::hasColumns('smart_import_outbox', [
            'import_id',
            'attempt_id',
            'recovery_count',
            'event_key',
            'payload_version',
            'delivery_status',
            'attempts',
            'available_at',
            'lease_token',
            'lease_expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('smart_import_notifications', [
            'outbox_id',
            'notifiable_type',
            'notifiable_id',
            'channel',
            'data',
            'read_at',
        ]));
        $this->assertTrue(Schema::hasColumns('smart_import_audit_entries', [
            'import_id',
            'tenant_id',
            'actor_type',
            'actor_id',
            'event',
            'reason_code',
            'mapping_revision',
            'staging_revision',
            'attempt_id',
            'metadata',
        ]));
    }

    #[Test]
    public function package_query_indexes_cover_session_row_and_preset_lookups(): void
    {
        $sessions = array_column(Schema::getIndexes('smart_imports'), 'columns');
        $rows = array_column(Schema::getIndexes('smart_import_rows'), 'columns');
        $presets = array_column(Schema::getIndexes('smart_import_presets'), 'columns');
        $audit = array_column(Schema::getIndexes('smart_import_audit_entries'), 'columns');

        $this->assertContains(['file_hash'], $sessions);
        $this->assertContains(['storage_disk', 'claimed_storage_path'], $sessions);
        $this->assertContains(['importer_key', 'status'], $sessions);
        $this->assertContains(['intake_status', 'created_at'], $sessions);
        $this->assertContains(['created_at', 'id'], $sessions);
        $this->assertContains(['import_id', 'validation_status'], $rows);
        $this->assertContains(['import_id', 'execution_status'], $rows);
        $this->assertContains(['importer_key', 'importer_version'], $presets);
        $this->assertContains(['import_id', 'created_at'], $audit);
        $this->assertContains(['import_id', 'id'], $audit);
    }

    #[Test]
    public function file_checksum_is_nullable_for_unverified_quarantine_records(): void
    {
        $checksumColumn = collect(Schema::getColumns('smart_imports'))->firstWhere('name', 'file_hash');

        $this->assertIsArray($checksumColumn);
        $this->assertTrue($checksumColumn['nullable']);
    }

    #[Test]
    public function models_cast_json_and_status_values_and_expose_relationships(): void
    {
        $session = $this->createSession([
            'intake_status' => IntakeStatus::Ready->value,
            'status' => ImportStatus::Staging->value,
            'mapping' => ['Full name' => 'name'],
            'options' => ['mode' => 'insert'],
            'attempt_history' => [['attempt' => 1, 'failed' => 1]],
        ]);

        $row = ImportRow::forceCreate([
            'import_id' => $session->id,
            'source_row_number' => 2,
            'raw_values' => ['Full name' => 'Ada'],
            'execution_history' => [['attempt' => 1, 'outcome' => 'failed']],
        ]);

        $preset = ImportPreset::forceCreate([
            'importer_key' => 'contacts',
            'importer_version' => '1',
            'scope_key' => 'user:42',
            'name' => 'Contacts',
            'source_fingerprint' => str_repeat('b', 64),
            'mapping' => ['Full name' => 'name'],
            'options' => ['mode' => 'insert'],
        ]);

        $this->assertSame(IntakeStatus::Ready, $session->intake_status);
        $this->assertSame(ImportStatus::Staging, $session->status);
        $this->assertSame(['Full name' => 'name'], $session->mapping);
        $this->assertSame(1, $session->attempt_history[0]['attempt']);
        $this->assertSame($session->id, $row->importSession->id);
        $this->assertSame(1, $session->rows()->count());
        $this->assertSame(['Full name' => 'name'], $preset->mapping);
    }

    #[Test]
    public function package_models_reject_mass_assignment(): void
    {
        foreach ([new ImportSession(), new ImportRow(), new ImportPreset(), new ImportOutboxEvent(), new ImportNotification(), new ImportAuditEntry()] as $model) {
            try {
                $model->fill([
                    'source' => 'web',
                    'importer_key' => 'untrusted',
                    'name' => 'Untrusted preset',
                ]);
            } catch (MassAssignmentException) {
                $this->assertSame([], $model->getAttributes());

                continue;
            }

            $this->fail('The model accepted untrusted attributes.');
        }
    }

    #[Test]
    public function source_path_is_unique_per_storage_disk(): void
    {
        $this->createSession();

        $this->expectException(QueryException::class);

        $this->createSession([
            'file_hash' => str_repeat('b', 64),
        ]);
    }

    #[Test]
    public function persisted_file_identity_fields_cannot_be_changed(): void
    {
        $session = $this->createSession();
        $identity = $session->only([
            'source',
            'original_filename',
            'storage_disk',
            'storage_path',
            'file_hash',
            'file_size',
            'file_type',
        ]);
        $replacements = [
            'source' => 'cli',
            'original_filename' => 'renamed.csv',
            'storage_disk' => 'private',
            'storage_path' => 'smart-import/processing/contacts.csv',
            'file_hash' => str_repeat('b', 64),
            'file_size' => 99,
            'file_type' => 'xlsx',
        ];

        foreach ($replacements as $field => $replacement) {
            $candidate = $session->fresh();
            $candidate->forceFill([$field => $replacement]);

            try {
                $candidate->save();
                $this->fail("Changing persisted file identity field [{$field}] should fail.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString($field, $exception->getMessage());
            }

            $this->assertSame($identity, $session->fresh()->only(array_keys($identity)));
        }
    }

    #[Test]
    public function a_source_row_is_unique_within_its_import_and_sheet(): void
    {
        $session = $this->createSession();
        $attributes = [
            'import_id' => $session->id,
            'source_sheet' => '',
            'source_row_number' => 2,
        ];

        ImportRow::forceCreate($attributes);

        $this->expectException(QueryException::class);

        ImportRow::forceCreate($attributes);
    }

    #[Test]
    public function preset_name_is_unique_within_importer_scope(): void
    {
        $attributes = [
            'importer_key' => 'contacts',
            'importer_version' => '1',
            'scope_key' => 'user:42',
            'name' => 'Contacts',
            'source_fingerprint' => str_repeat('c', 64),
            'mapping' => ['Full name' => 'name'],
        ];

        ImportPreset::forceCreate($attributes);

        $this->expectException(QueryException::class);

        ImportPreset::forceCreate($attributes);
    }

    #[Test]
    public function deleting_a_session_cascades_to_its_rows(): void
    {
        $session = $this->createSession();
        $row = ImportRow::forceCreate([
            'import_id' => $session->id,
            'source_row_number' => 2,
        ]);

        $session->delete();

        $this->assertDatabaseMissing('smart_import_rows', ['id' => $row->id]);
    }

    #[Test]
    public function package_migration_can_be_rolled_back(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_11_15_000001_create_smart_import_tables.php';
        $migration->down();

        $this->assertFalse(Schema::hasTable('smart_import_rows'));
        $this->assertFalse(Schema::hasTable('smart_import_presets'));
        $this->assertFalse(Schema::hasTable('smart_imports'));
    }

    private function createSession(array $overrides = []): ImportSession
    {
        return ImportSession::forceCreate(array_merge([
            'source' => 'web',
            'original_filename' => 'contacts.csv',
            'storage_disk' => 'local',
            'storage_path' => 'smart-import/inbox/contacts.csv',
            'file_hash' => str_repeat('a', 64),
            'file_size' => 12,
            'file_type' => 'csv',
        ], $overrides));
    }
}
