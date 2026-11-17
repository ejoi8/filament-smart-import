<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Models\ImportSession;
use SmartImport\Tests\Fixtures\StaticAuthenticatableUserProvider;
use SmartImport\Tests\TestCase;

class DiscoverFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Auth::provider('smart-import-static', fn ($app, array $config) => new StaticAuthenticatableUserProvider());
        config()->set('auth.providers.smart-import-static', ['driver' => 'smart-import-static']);
        config()->set('auth.guards.smart-import-test', [
            'driver' => 'session',
            'provider' => 'smart-import-static',
        ]);
        Auth::forgetGuards();
        Gate::define('create', fn (): bool => true);
    }

    #[Test]
    public function command_requires_an_explicit_actor_and_registers_only_authorized_discovered_files(): void
    {
        $this->assertSame(1, Artisan::call('smart-import:discover'));
        $this->assertStringContainsString('--actor', Artisan::output());
        $this->assertDatabaseCount('smart_imports', 0);

        Storage::disk('local')->put('smart-import/inbox/contacts.csv', "name,email\nAda,ada@example.test\n");

        $exitCode = Artisan::call('smart-import:discover', [
            '--actor' => '7',
            '--guard' => 'smart-import-test',
        ]);

        $session = ImportSession::query()->firstOrFail();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 new file(s) registered', Artisan::output());
        $this->assertSame('7', $session->created_by_id);
        $this->assertSame(IntakeStatus::Claimed, $session->intake_status);
        $this->assertNull($session->importer_key);
        $this->assertNull($session->status);
        $this->assertSame(0, $session->rows()->count());
    }

    #[Test]
    public function unauthorized_console_actor_cannot_claim_a_file(): void
    {
        Gate::define('create', fn (): bool => false);
        Storage::disk('local')->put('smart-import/inbox/contacts.csv', "name,email\nAda,ada@example.test\n");

        $exitCode = Artisan::call('smart-import:discover', [
            '--actor' => '7',
            '--guard' => 'smart-import-test',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not authorized', Artisan::output());
        $this->assertTrue(Storage::disk('local')->exists('smart-import/inbox/contacts.csv'));
        $this->assertDatabaseCount('smart_imports', 0);
    }
}
