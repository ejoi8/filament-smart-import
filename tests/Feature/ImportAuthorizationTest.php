<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportPresetScope;
use SmartImport\Contracts\ImportSessionScope;
use SmartImport\Models\ImportSession;
use SmartImport\Services\ImportAuthorization;
use SmartImport\Tests\Fixtures\BaseImportDefinition;
use SmartImport\Tests\TestCase;

class ImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function session_operations_use_the_explicit_actors_gate_policy(): void
    {
        Gate::define('view', fn (Authenticatable $actor, ImportSession $session): bool =>
            $actor->tenant_id === $session->tenant_id
        );

        $actor = new GenericUser(['id' => 7, 'tenant_id' => 'north']);
        $session = $this->createSession(['tenant_id' => 'north']);
        $authorization = $this->app->make(ImportAuthorization::class);

        $authorization->authorize('view', $actor, $session);

        $this->expectException(AuthorizationException::class);

        $authorization->authorize(
            'view',
            new GenericUser(['id' => 8, 'tenant_id' => 'south']),
            $session,
        );
    }

    #[Test]
    public function importer_use_requires_gate_approval_for_the_registered_definition(): void
    {
        $definition = new class extends BaseImportDefinition
        {
            public function key(): string
            {
                return 'contacts';
            }
        };

        Gate::define('useImporter', fn (Authenticatable $actor, ImportDefinition $subject): bool =>
            $actor->getAuthIdentifier() === 7 && $subject->key() === 'contacts'
        );

        $authorization = $this->app->make(ImportAuthorization::class);
        $authorization->authorize('useImporter', new GenericUser(['id' => 7]), $definition);

        $this->expectException(AuthorizationException::class);

        $authorization->authorize('useImporter', new GenericUser(['id' => 8]), $definition);
    }

    #[Test]
    public function session_discovery_requires_view_any_authorization(): void
    {
        Gate::define('viewAny', fn (): bool => false);

        $this->expectException(AuthorizationException::class);

        $this->app->make(ImportAuthorization::class)->scopeSessions(
            ImportSession::query(),
            new GenericUser(['id' => 7]),
        );
    }

    #[Test]
    public function default_scope_limits_discovery_to_owned_tenantless_sessions(): void
    {
        Gate::define('viewAny', fn (): bool => true);

        $actor = new GenericUser(['id' => 7]);
        $ownedSession = $this->createSession([
            'created_by_type' => GenericUser::class,
            'created_by_id' => '7',
        ]);
        $this->createSession([
            'created_by_type' => GenericUser::class,
            'created_by_id' => '8',
        ]);
        $this->createSession([
            'created_by_type' => GenericUser::class,
            'created_by_id' => '7',
            'tenant_id' => 'north',
        ]);

        $sessions = $this->app->make(ImportAuthorization::class)
            ->scopeSessions(ImportSession::query(), $actor)
            ->pluck('id')
            ->all();

        $this->assertSame([$ownedSession->id], $sessions);
    }

    #[Test]
    public function host_can_configure_a_tenant_aware_session_scope(): void
    {
        config()->set('smart-import.authorization.session_scope', TenantAwareTestSessionScope::class);
        Gate::define('viewAny', fn (): bool => true);

        $this->createSession(['tenant_id' => 'north']);
        $southSession = $this->createSession(['tenant_id' => 'south']);

        $sessions = $this->app->make(ImportAuthorization::class)
            ->scopeSessions(
                ImportSession::query(),
                new TenantAwareTestUser(['id' => 7, 'tenant_id' => 'south']),
            )
            ->pluck('id')
            ->all();

        $this->assertSame([$southSession->id], $sessions);
    }

    #[Test]
    public function configured_scope_must_implement_the_scope_contract(): void
    {
        config()->set('smart-import.authorization.session_scope', \stdClass::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        $this->app->make(ImportSessionScope::class);
    }

    #[Test]
    public function configured_preset_scope_must_implement_its_contract(): void
    {
        config()->set('smart-import.authorization.preset_scope', \stdClass::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        $this->app->make(ImportPresetScope::class);
    }

    private function createSession(array $overrides = []): ImportSession
    {
        return ImportSession::forceCreate(array_merge([
            'source' => 'web',
            'original_filename' => 'contacts.csv',
            'storage_disk' => 'local',
            'storage_path' => 'smart-import/inbox/'.uniqid('', true).'.csv',
            'file_hash' => hash('sha256', uniqid('', true)),
            'file_size' => 12,
            'file_type' => 'csv',
        ], $overrides));
    }
}

final class TenantAwareTestUser extends GenericUser
{
    public function tenantId(): string
    {
        return $this->tenant_id;
    }
}

final class TenantAwareTestSessionScope implements ImportSessionScope
{
    public function apply(Builder $query, Authenticatable $actor): Builder
    {
        if (! $actor instanceof TenantAwareTestUser) {
            throw new AuthorizationException('Tenant context is required.');
        }

        return $query->where('tenant_id', $actor->tenantId());
    }
}
