<?php

namespace SmartImport\Tests\Feature;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Models\ImportSession;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Tests\Fixtures\BaseImportDefinition;
use SmartImport\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ImportCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creation_stamps_the_actor_and_starts_in_discovered_unassigned_state(): void
    {
        Gate::define('create', fn (): bool => true);
        $actor = new GenericUser(['id' => 7]);

        $session = $this->coordinator()->create($this->sourceAttributes(), $actor);

        $this->assertSame(GenericUser::class, $session->created_by_type);
        $this->assertSame('7', $session->created_by_id);
        $this->assertSame(IntakeStatus::Discovered, $session->intake_status);
        $this->assertNull($session->status);
        $this->assertNull($session->importer_key);
    }

    #[Test]
    public function creation_persists_source_metadata_with_a_canonical_checksum(): void
    {
        Gate::define('create', fn (): bool => true);
        $attributes = $this->sourceAttributes();
        $attributes['file_hash'] = strtoupper($attributes['file_hash']);

        $session = $this->coordinator()->create($attributes, new GenericUser(['id' => 7]));

        $this->assertSame('web', $session->source);
        $this->assertSame('contacts.csv', $session->original_filename);
        $this->assertSame('local', $session->storage_disk);
        $this->assertSame($attributes['storage_path'], $session->storage_path);
        $this->assertSame(strtolower($attributes['file_hash']), $session->file_hash);
        $this->assertSame(12, $session->file_size);
        $this->assertSame('csv', $session->file_type);
    }

    #[Test]
    public function creation_is_denied_when_the_actor_lacks_create_permission(): void
    {
        Gate::define('create', fn (): bool => false);

        $this->expectException(AuthorizationException::class);

        $this->coordinator()->create($this->sourceAttributes(), new GenericUser(['id' => 7]));
    }

    #[Test]
    public function creation_requires_registered_and_authorized_importers(): void
    {
        config()->set('smart-import.importers', [CoordinatorTestImporter::class]);
        Gate::define('create', fn (): bool => true);
        Gate::define('useImporter', fn (Authenticatable $actor, ImportDefinition $definition): bool =>
            $actor->getAuthIdentifier() === 7 && $definition->key() === 'contacts'
        );

        $coordinator = $this->coordinator();
        $attributes = array_merge($this->sourceAttributes(), ['importer_key' => 'contacts']);
        $session = $coordinator->create($attributes, new GenericUser(['id' => 7]));

        $this->assertSame('contacts', $session->importer_key);
        $this->assertSame('1', $session->importer_version);

        $this->expectException(AuthorizationException::class);

        $coordinator->create($attributes, new GenericUser(['id' => 8]));
    }

    #[Test]
    public function tenant_assignment_requires_an_explicit_matching_gate_ability(): void
    {
        Gate::define('create', fn (): bool => true);
        Gate::define('createForTenant', fn (Authenticatable $actor, string $tenantId): bool =>
            $actor->tenant_id === $tenantId
        );

        $session = $this->coordinator()->create(
            array_merge($this->sourceAttributes(), ['tenant_id' => 'north']),
            new GenericUser(['id' => 7, 'tenant_id' => 'north']),
        );

        $this->assertSame('north', $session->tenant_id);

        $this->expectException(AuthorizationException::class);

        $this->coordinator()->create(
            array_merge($this->sourceAttributes(), ['tenant_id' => 'south']),
            new GenericUser(['id' => 7, 'tenant_id' => 'north']),
        );
    }

    #[Test]
    public function zero_like_tenant_identifiers_still_require_tenant_authorization(): void
    {
        Gate::define('create', fn (): bool => true);
        Gate::define('createForTenant', fn (): bool => false);

        $this->expectException(AuthorizationException::class);

        $this->coordinator()->create(
            array_merge($this->sourceAttributes(), ['tenant_id' => '0']),
            new GenericUser(['id' => 7]),
        );
    }

    #[Test]
    public function get_applies_actor_scope_and_session_view_policy(): void
    {
        Gate::define('create', fn (): bool => true);
        Gate::define('view', fn (Authenticatable $actor, ImportSession $session): bool =>
            (string) $actor->getAuthIdentifier() === $session->created_by_id
        );

        $coordinator = $this->coordinator();
        $owner = new GenericUser(['id' => 7]);
        $session = $coordinator->create($this->sourceAttributes(), $owner);

        $this->assertSame($session->id, $coordinator->get($session->id, $owner)->id);

        $this->expectException(ModelNotFoundException::class);

        $coordinator->get($session->id, new GenericUser(['id' => 8]));
    }

    #[Test]
    public function importer_assignment_is_authorized_and_marks_an_unstaged_file_ready(): void
    {
        config()->set('smart-import.importers', [CoordinatorTestImporter::class]);
        Gate::define('create', fn (): bool => true);
        Gate::define('update', fn (): bool => true);
        Gate::define('useImporter', fn (Authenticatable $actor, ImportDefinition $definition): bool =>
            $actor->getAuthIdentifier() === 7 && $definition->key() === 'contacts'
        );

        $coordinator = $this->coordinator();
        $actor = new GenericUser(['id' => 7]);
        $session = $coordinator->create($this->sourceAttributes(), $actor);
        $assigned = $coordinator->assignImporter($session->id, 'contacts', $actor);

        $this->assertSame('contacts', $assigned->importer_key);
        $this->assertSame('1', $assigned->importer_version);
        $this->assertSame(\SmartImport\Tests\Fixtures\ImportTargetModel::class, $assigned->target_model);
        $this->assertSame(IntakeStatus::Ready, $assigned->intake_status);
        $this->assertNull($assigned->status);
        $this->assertSame($session->storage_path, $assigned->storage_path);

        $deniedSession = $coordinator->create($this->sourceAttributes(), $actor);
        Gate::define('useImporter', fn (): bool => false);

        try {
            $coordinator->assignImporter($deniedSession->id, 'contacts', $actor);
            $this->fail('Importer assignment must require importer authorization.');
        } catch (AuthorizationException) {
            $this->assertNull($deniedSession->fresh()->importer_key);
            $this->assertSame(IntakeStatus::Discovered, $deniedSession->fresh()->intake_status);
        }

        Gate::define('useImporter', fn (Authenticatable $user, ImportDefinition $definition): bool =>
            $user->getAuthIdentifier() === 7 && $definition->key() === 'contacts'
        );

        $this->expectException(ModelNotFoundException::class);
        $coordinator->assignImporter($session->id, 'contacts', new GenericUser(['id' => 8]));
    }

    #[Test]
    public function importer_cannot_be_reassigned_after_staging_or_to_an_archived_file(): void
    {
        config()->set('smart-import.importers', [CoordinatorTestImporter::class]);
        Gate::define('create', fn (): bool => true);
        Gate::define('update', fn (): bool => true);
        Gate::define('useImporter', fn (): bool => true);

        $coordinator = $this->coordinator();
        $actor = new GenericUser(['id' => 7]);
        $session = $coordinator->create($this->sourceAttributes(), $actor);
        $coordinator->transition($session->id, ImportStatus::Uploaded, $actor);

        try {
            $coordinator->assignImporter($session->id, 'contacts', $actor);
            $this->fail('A staged import cannot be reassigned.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('after staging', $exception->getMessage());
        }

        $archived = $coordinator->create($this->sourceAttributes(), $actor);
        $coordinator->transition($archived->id, IntakeStatus::Archived, $actor);

        $this->expectException(DomainException::class);
        $coordinator->assignImporter($archived->id, 'contacts', $actor);
    }

    #[Test]
    public function duplicate_status_transition_reports_only_the_first_change(): void
    {
        Gate::define('create', fn (): bool => true);
        Gate::define('update', fn (): bool => true);

        $coordinator = $this->coordinator();
        $actor = new GenericUser(['id' => 7]);
        $session = $coordinator->create($this->sourceAttributes(), $actor);

        $this->assertTrue($coordinator->transition($session->id, ImportStatus::Uploaded, $actor));
        $this->assertSame(ImportStatus::Uploaded, $session->fresh()->status);

        $updatedAt = $session->fresh()->updated_at;
        $this->travel(1)->seconds();

        $this->assertFalse($coordinator->transition($session->id, ImportStatus::Uploaded, $actor));
        $this->assertEquals($updatedAt, $session->fresh()->updated_at);
    }

    #[Test]
    public function intake_transition_uses_the_same_locked_idempotent_coordinator_path(): void
    {
        Gate::define('create', fn (): bool => true);
        Gate::define('update', fn (): bool => true);

        $coordinator = $this->coordinator();
        $actor = new GenericUser(['id' => 7]);
        $session = $coordinator->create($this->sourceAttributes(), $actor);

        $this->assertTrue($coordinator->transition($session->id, IntakeStatus::Ready, $actor));
        $this->assertSame(IntakeStatus::Ready, $session->fresh()->intake_status);
        $this->assertFalse($coordinator->transition($session->id, IntakeStatus::Ready, $actor));
    }

    #[Test]
    public function discovered_file_can_be_claimed_and_released_without_changing_its_identity(): void
    {
        Gate::define('create', fn (): bool => true);
        Gate::define('update', fn (): bool => true);

        $coordinator = $this->coordinator();
        $actor = new GenericUser(['id' => 7]);
        $session = $coordinator->create($this->sourceAttributes(), $actor);
        $identity = $session->only([
            'source',
            'original_filename',
            'storage_disk',
            'storage_path',
            'file_hash',
            'file_size',
            'file_type',
        ]);

        $this->assertTrue($coordinator->transition($session->id, IntakeStatus::Claimed, $actor));
        $this->assertSame(IntakeStatus::Claimed, $session->fresh()->intake_status);
        $this->assertSame($identity, $session->fresh()->only(array_keys($identity)));

        $this->assertTrue($coordinator->transition($session->id, IntakeStatus::Ready, $actor));
        $this->assertSame(IntakeStatus::Ready, $session->fresh()->intake_status);
        $this->assertSame($identity, $session->fresh()->only(array_keys($identity)));
    }

    #[Test]
    public function session_update_policy_denial_prevents_state_persistence(): void
    {
        Gate::define('create', fn (): bool => true);
        Gate::define('update', fn (): bool => false);

        $coordinator = $this->coordinator();
        $actor = new GenericUser(['id' => 7]);
        $session = $coordinator->create($this->sourceAttributes(), $actor);

        try {
            $coordinator->transition($session->id, ImportStatus::Uploaded, $actor);
            $this->fail('A denied update should not persist a transition.');
        } catch (AuthorizationException) {
            $this->assertNull($session->fresh()->status);
        }
    }

    #[Test]
    public function illegal_or_unauthorized_transitions_do_not_change_persisted_state(): void
    {
        Gate::define('create', fn (): bool => true);
        Gate::define('update', fn (Authenticatable $actor): bool => $actor->getAuthIdentifier() === 7);

        $coordinator = $this->coordinator();
        $owner = new GenericUser(['id' => 7]);
        $session = $coordinator->create($this->sourceAttributes(), $owner);

        try {
            $coordinator->transition($session->id, ImportStatus::Completed, $owner);
            $this->fail('An illegal transition should be rejected.');
        } catch (DomainException) {
            $this->assertNull($session->fresh()->status);
        }

        $this->expectException(ModelNotFoundException::class);

        $coordinator->transition(
            $session->id,
            ImportStatus::Uploaded,
            new GenericUser(['id' => 8]),
        );
    }

    #[Test]
    public function creation_rejects_fields_outside_the_session_intake_allowlist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported import session creation field');

        $this->coordinator()->create(
            array_merge($this->sourceAttributes(), ['status' => 'completed']),
            new GenericUser(['id' => 7]),
        );
    }

    private function coordinator(): ImportCoordinator
    {
        return $this->app->make(ImportCoordinator::class);
    }

    private function sourceAttributes(): array
    {
        $path = 'smart-import/inbox/'.uniqid('source-', true).'.csv';

        return [
            'source' => 'web',
            'original_filename' => 'contacts.csv',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'file_hash' => hash('sha256', $path),
            'file_size' => 12,
            'file_type' => 'csv',
        ];
    }
}

final class CoordinatorTestImporter extends BaseImportDefinition
{
    public function key(): string
    {
        return 'contacts';
    }
}
