<?php

namespace SmartImport\Console\Commands;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;
use SmartImport\Services\FileIntakeService;
use Throwable;

final class DiscoverFilesCommand extends Command
{
    protected $signature = 'smart-import:discover
        {--actor= : Required ID of the authorized actor from the selected guard}
        {--guard= : Guard used to resolve the actor (defaults to the configured default guard)}
        {--tenant= : Optional trusted tenant identifier for intake authorization}';

    protected $description = 'Discover and claim completed files from the private Smart Import inbox';

    public function handle(FileIntakeService $intake): int
    {
        $actorId = $this->option('actor');

        if (! is_string($actorId) || trim($actorId) === '') {
            $this->error('Provide an authorized actor ID with --actor.');

            return self::FAILURE;
        }

        $guardName = $this->option('guard') ?: config('auth.defaults.guard');

        if (! is_string($guardName) || trim($guardName) === '') {
            $this->error('No authentication guard is configured for discovery.');

            return self::FAILURE;
        }

        try {
            $guard = Auth::guard($guardName);
            $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;
        } catch (Throwable) {
            $this->error('The selected authentication guard is not available.');

            return self::FAILURE;
        }

        if (! $provider instanceof UserProvider) {
            $this->error('The selected authentication guard cannot resolve an actor from an ID.');

            return self::FAILURE;
        }

        try {
            $actor = $provider->retrieveById($actorId);
        } catch (Throwable) {
            $actor = null;
        }

        if (! $actor instanceof Authenticatable) {
            $this->error('The requested actor could not be found by the selected authentication provider.');

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant');

        try {
            $sessions = $intake->discoverInbox($actor, is_string($tenantId) && $tenantId !== '' ? $tenantId : null);
        } catch (AuthorizationException) {
            $this->error('The selected actor is not authorized to discover Smart Import files.');

            return self::FAILURE;
        } catch (LockTimeoutException) {
            $this->error('Another Smart Import discovery is still running. Try again shortly.');

            return self::FAILURE;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Discovery completed. %d new file(s) registered.', count($sessions)));

        return self::SUCCESS;
    }
}
