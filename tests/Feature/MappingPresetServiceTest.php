<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportPresetScope;
use SmartImport\Models\ImportPreset;
use SmartImport\Services\ImporterRegistry;
use SmartImport\Services\MappingPresetService;
use SmartImport\Tests\Fixtures\ColumnMappingDefinition;
use SmartImport\Tests\TestCase;

class MappingPresetServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('smart-import.importers', [ColumnMappingDefinition::class]);
        $this->app->forgetInstance(ImporterRegistry::class);

        Gate::define('useImporter', fn (Authenticatable $actor, ImportDefinition $definition): bool =>
            $definition->key() === 'contacts'
        );
        Gate::define('viewAny', fn (Authenticatable $actor, string $class): bool => $class === ImportPreset::class);
        Gate::define('view', fn (Authenticatable $actor, ImportPreset $preset): bool => true);
        Gate::define('create', fn (Authenticatable $actor, string $class): bool => $class === ImportPreset::class);
        Gate::define('update', fn (Authenticatable $actor, ImportPreset $preset): bool => true);
    }

    #[Test]
    public function save_persists_versioned_mapping_signature_options_and_explicit_ignored_columns(): void
    {
        $preset = $this->service()->save(
            'contacts',
            'Contact import',
            0,
            'Contacts',
            2,
            [1 => 'Name', 2 => 'E-mail', 3 => 'Notes'],
            [1 => 'name', 2 => 'email', 3 => null],
            new GenericUser(['id' => 7]),
            ['reader' => ['csv_delimiter' => ';', 'csv_encoding' => 'UTF-8']],
        );

        $this->assertSame('contacts', $preset->importer_key);
        $this->assertSame('1', $preset->importer_version);
        $this->assertSame('Contact import', $preset->name);
        $this->assertSame([1 => 'name', 2 => 'email', 3 => null], $preset->mapping);
        $this->assertSame([
            'reader' => ['csv_delimiter' => ';', 'csv_encoding' => 'UTF-8'],
            'importer' => [],
        ], $preset->options);
        $this->assertSame(GenericUser::class, $preset->created_by_type);
        $this->assertSame('7', $preset->created_by_id);
        $this->assertSame(64, strlen($preset->source_fingerprint));
        $this->assertSame(70, strlen($preset->scope_key));
    }

    #[Test]
    public function resolve_returns_mapping_only_when_version_and_sheet_header_fingerprint_match(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $headers = [1 => 'Name', 2 => 'E-mail', 3 => 'Notes'];
        $preset = $this->service()->save(
            'contacts', 'Contact import', 0, 'Contacts', 2, $headers,
            [1 => 'name', 2 => 'email', 3 => null], $actor,
        );

        $compatible = $this->service()->resolve(
            $preset->id,
            'contacts',
            0,
            'Contacts',
            2,
            [3 => 'Notes', 1 => 'Name', 2 => 'E-mail'],
            $actor,
            ['reader' => ['csv_delimiter' => null, 'csv_encoding' => 'guess']],
        );

        $this->assertTrue($compatible['compatible']);
        $this->assertNull($compatible['incompatibility']);
        $this->assertSame([1 => 'name', 2 => 'email', 3 => null], $compatible['mapping']);

        $staleVersion = ImportPreset::query()->whereKey($preset->id)->update(['importer_version' => '0']);
        $this->assertSame(1, $staleVersion);

        $incompatible = $this->service()->resolve(
            $preset->id,
            'contacts',
            0,
            'Contacts',
            2,
            $headers,
            $actor,
        );

        $this->assertFalse($incompatible['compatible']);
        $this->assertSame('importer_version', $incompatible['incompatibility']);
        $this->assertNull($incompatible['mapping']);
        $this->assertNull($incompatible['options']);
    }

    #[Test]
    public function a_changed_sheet_or_header_signature_returns_no_stale_mapping(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $preset = $this->service()->save(
            'contacts', 'Contact import', 0, 'Contacts', 2,
            [1 => 'Name', 2 => 'E-mail'], [1 => 'name', 2 => 'email'], $actor,
        );

        $resolved = $this->service()->resolve(
            $preset->id,
            'contacts',
            1,
            'Contacts',
            2,
            [1 => 'Name', 2 => 'E-mail'],
            $actor,
        );

        $this->assertFalse($resolved['compatible']);
        $this->assertSame('source_fingerprint', $resolved['incompatibility']);
        $this->assertNull($resolved['mapping']);
    }

    #[Test]
    public function csv_reader_options_are_frozen_and_must_match_before_a_preset_can_be_applied(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $headers = [1 => 'Name', 2 => 'E-mail'];
        $preset = $this->service()->save(
            'contacts',
            'Semicolon contacts',
            0,
            'Contacts',
            1,
            $headers,
            [1 => 'name', 2 => 'email'],
            $actor,
            ['reader' => ['csv_delimiter' => ';', 'csv_encoding' => 'UTF-8']],
        );

        $resolved = $this->service()->resolve(
            $preset->id,
            'contacts',
            0,
            'Contacts',
            1,
            $headers,
            $actor,
            ['reader' => ['csv_delimiter' => ';', 'csv_encoding' => 'UTF-8']],
        );

        $this->assertTrue($resolved['compatible']);
        $this->assertSame(';', $resolved['options']['reader']['csv_delimiter']);
        $this->assertSame('UTF-8', $resolved['options']['reader']['csv_encoding']);

        $incompatible = $this->service()->resolve(
            $preset->id,
            'contacts',
            0,
            'Contacts',
            1,
            $headers,
            $actor,
            ['reader' => ['csv_delimiter' => ',', 'csv_encoding' => 'UTF-8']],
        );

        $this->assertFalse($incompatible['compatible']);
        $this->assertSame('reader_options', $incompatible['incompatibility']);
        $this->assertNull($incompatible['mapping']);
    }

    #[Test]
    public function duplicate_header_mappings_keep_their_original_source_column_indexes(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $headers = [1 => 'Name', 2 => 'Name', 3 => 'E-mail'];
        $preset = $this->service()->save(
            'contacts',
            'Duplicate labels',
            0,
            'Contacts',
            1,
            $headers,
            [1 => 'name', 2 => null, 3 => 'email'],
            $actor,
        );

        $resolved = $this->service()->resolve(
            $preset->id,
            'contacts',
            0,
            'Contacts',
            1,
            $headers,
            $actor,
        );

        $this->assertTrue($resolved['compatible']);
        $this->assertSame([1 => 'name', 2 => null, 3 => 'email'], $resolved['mapping']);
    }

    #[Test]
    public function malformed_stored_mapping_is_not_returned_as_compatible(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $headers = [1 => 'Name', 2 => 'E-mail'];
        $preset = $this->service()->save(
            'contacts', 'Contacts', 0, 'Contacts', 1, $headers, [1 => 'name', 2 => 'email'], $actor,
        );
        $preset->forceFill(['mapping' => [1 => 'unapproved_field', 2 => 'email']])->save();

        $resolved = $this->service()->resolve($preset->id, 'contacts', 0, 'Contacts', 1, $headers, $actor);

        $this->assertFalse($resolved['compatible']);
        $this->assertSame('mapping_invalid', $resolved['incompatibility']);
        $this->assertNull($resolved['mapping']);
    }

    #[Test]
    public function saving_the_same_name_updates_one_owned_preset(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $service = $this->service();
        $headers = [1 => 'Name', 2 => 'E-mail'];
        $first = $service->save('contacts', 'Contacts', 0, 'Contacts', 1, $headers, [1 => 'name', 2 => null], $actor);
        $updated = $service->save('contacts', 'Contacts', 0, 'Contacts', 1, $headers, [1 => 'name', 2 => 'email'], $actor);

        $this->assertSame($first->id, $updated->id);
        $this->assertSame([1 => 'name', 2 => 'email'], $updated->mapping);
        $this->assertSame(1, ImportPreset::query()->count());
    }

    #[Test]
    public function default_scope_hides_another_actors_presets_and_loads_only_metadata_for_lists(): void
    {
        $owner = new GenericUser(['id' => 7]);
        $other = new GenericUser(['id' => 8]);
        $preset = $this->service()->save(
            'contacts', 'Contacts', 0, 'Contacts', 1,
            [1 => 'Name', 2 => 'E-mail'], [1 => 'name', 2 => 'email'], $owner,
        );

        $this->assertCount(0, $this->service()->forImporter('contacts', $other));
        $this->assertNull($this->service()->forImporter('contacts', $owner)->first()->mapping);

        $this->expectException(ModelNotFoundException::class);
        $this->service()->resolve(
            $preset->id,
            'contacts',
            0,
            'Contacts',
            1,
            [1 => 'Name', 2 => 'E-mail'],
            $other,
        );
    }

    #[Test]
    public function host_can_configure_a_tenant_preset_scope(): void
    {
        config()->set('smart-import.authorization.preset_scope', TenantMappingPresetScope::class);
        $this->app->forgetInstance(ImportPresetScope::class);
        $this->app->forgetInstance(\SmartImport\Services\ImportAuthorization::class);
        $this->app->forgetInstance(MappingPresetService::class);

        $owner = new GenericUser(['id' => 7, 'tenant_id' => 'north']);
        $peer = new GenericUser(['id' => 8, 'tenant_id' => 'north']);
        $otherTenant = new GenericUser(['id' => 9, 'tenant_id' => 'south']);
        $preset = $this->service()->save(
            'contacts', 'Tenant contacts', 0, 'Contacts', 1,
            [1 => 'Name', 2 => 'E-mail'], [1 => 'name', 2 => 'email'], $owner,
        );

        $this->assertSame('tenant:north', $preset->scope_key);
        $this->assertCount(1, $this->service()->forImporter('contacts', $peer));
        $this->assertCount(0, $this->service()->forImporter('contacts', $otherTenant));
    }

    #[Test]
    public function creation_authorization_and_safe_option_checks_are_enforced(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $arguments = [
            'contacts', 'Contacts', 0, 'Contacts', 1,
            [1 => 'Name', 2 => 'E-mail'], [1 => 'name', 2 => 'email'], $actor,
        ];

        Gate::define('create', fn (): bool => false);

        try {
            $this->service()->save(...$arguments);
            $this->fail('Preset creation without permission must be rejected.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('smart_import_presets', 0);
        }

        Gate::define('create', fn (): bool => true);

        try {
            $this->service()->save(...[...$arguments, ['api_token' => 'sensitive']]);
            $this->fail('Sensitive option names must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('looks sensitive', $exception->getMessage());
        }

        $this->assertDatabaseCount('smart_import_presets', 0);
    }

    #[Test]
    public function preset_listing_requires_view_any_authorization(): void
    {
        Gate::define('viewAny', fn (): bool => false);

        $this->expectException(AuthorizationException::class);

        $this->service()->forImporter('contacts', new GenericUser(['id' => 7]));
    }

    private function service(): MappingPresetService
    {
        return $this->app->make(MappingPresetService::class);
    }
}

final class TenantMappingPresetScope implements ImportPresetScope
{
    public function keyFor(Authenticatable $actor): string
    {
        if (! $actor instanceof GenericUser || ! is_string($actor->tenant_id ?? null)) {
            throw new AuthorizationException('A trusted tenant context is required for mapping presets.');
        }

        return 'tenant:'.$actor->tenant_id;
    }

    public function apply(Builder $query, Authenticatable $actor): Builder
    {
        return $query->where('scope_key', $this->keyFor($actor));
    }
}
