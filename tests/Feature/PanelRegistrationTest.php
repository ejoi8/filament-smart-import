<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Services\HubPanelRegistry;
use SmartImport\Services\ImportAuthorization;
use SmartImport\Tests\TestCase;

class PanelRegistrationTest extends TestCase
{
    #[Test]
    public function the_default_configuration_registers_no_hub_panels(): void
    {
        $registry = $this->app->make(HubPanelRegistry::class);

        $this->assertSame([], $registry->panelIds());
        $this->assertFalse($registry->isEnabledFor('admin'));
    }

    #[Test]
    public function configured_panel_uses_its_gate_ability_and_explicit_actor(): void
    {
        config()->set('smart-import.filament.panels', [
            'admin' => 'accessSmartImportHub',
            'support' => 'accessSupportImportHub',
        ]);
        Gate::define('accessSmartImportHub', fn (Authenticatable $actor, string $panelId): bool =>
            $actor->getAuthIdentifier() === 7 && $panelId === 'admin'
        );

        $registry = $this->app->make(HubPanelRegistry::class);

        $this->assertSame(['admin', 'support'], $registry->panelIds());
        $this->assertTrue($registry->isEnabledFor('admin'));
        $this->assertSame('accessSmartImportHub', $registry->accessAbility('admin'));

        $this->app->make(ImportAuthorization::class)
            ->authorizeHubPanel('admin', new GenericUser(['id' => 7]));

        $this->expectException(AuthorizationException::class);

        $this->app->make(ImportAuthorization::class)
            ->authorizeHubPanel('admin', new GenericUser(['id' => 8]));
    }

    #[Test]
    public function an_unlisted_panel_is_denied_even_when_an_ability_exists(): void
    {
        config()->set('smart-import.filament.panels', ['admin' => 'accessSmartImportHub']);
        Gate::define('accessSmartImportHub', fn (): bool => true);

        $this->expectException(AuthorizationException::class);

        $this->app->make(ImportAuthorization::class)
            ->authorizeHubPanel('staff', new GenericUser(['id' => 7]));
    }

    #[Test]
    public function non_array_or_wildcard_panel_configuration_is_rejected(): void
    {
        config()->set('smart-import.filament.panels', 'admin');

        try {
            $this->app->make(HubPanelRegistry::class);
            $this->fail('Non-array panel configuration should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must be a panel ID', $exception->getMessage());
        }

        config()->set('smart-import.filament.panels', ['*' => 'accessSmartImportHub']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wildcard registration is not supported');

        $this->app->make(HubPanelRegistry::class);
    }
}
