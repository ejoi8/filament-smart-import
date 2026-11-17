<?php

namespace SmartImport;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use SmartImport\Console\Commands\DiscoverFilesCommand;
use SmartImport\Console\Commands\RecoverImportsCommand;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportPresetScope;
use SmartImport\Contracts\ImportSessionScope;
use SmartImport\Services\ActorOwnedImportPresetScope;
use SmartImport\Services\ActorOwnedImportSessionScope;
use SmartImport\Services\ColumnMapper;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\HubPanelRegistry;
use SmartImport\Services\ImportAuthorization;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImportExportService;
use SmartImport\Services\ImportExecutionService;
use SmartImport\Services\ImportExecutionDispatcher;
use SmartImport\Services\ImportExecutionLifecycle;
use SmartImport\Services\ImportProgressService;
use SmartImport\Services\ImportRetryService;
use SmartImport\Services\ImportRecoveryService;
use SmartImport\Services\ImportHookDispatcher;
use SmartImport\Services\ImportStagingService;
use SmartImport\Services\ImporterRegistry;
use SmartImport\Services\MappingPresetService;
use SmartImport\Services\RowDataProcessor;
use SmartImport\Services\SpreadsheetReader;
use SmartImport\Services\StagedRowService;

class SmartImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/smart-import.php', 'smart-import');

        $this->app->singleton(ImporterRegistry::class, function ($app): ImporterRegistry {
            $classes = config('smart-import.importers', []);

            if (! is_array($classes)) {
                throw new InvalidArgumentException('The [smart-import.importers] configuration value must be an array of definition classes.');
            }

            $definitions = [];

            foreach ($classes as $class) {
                if (! is_string($class) || ! is_a($class, ImportDefinition::class, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Configured importer [%s] must implement [%s].',
                        is_string($class) ? $class : get_debug_type($class),
                        ImportDefinition::class,
                    ));
                }

                $definitions[] = $app->make($class);
            }

            return new ImporterRegistry($definitions);
        });

        $this->app->singleton(ImportSessionScope::class, function ($app): ImportSessionScope {
            $class = config('smart-import.authorization.session_scope', ActorOwnedImportSessionScope::class);

            if (! is_string($class) || ! is_a($class, ImportSessionScope::class, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Configured import session scope [%s] must implement [%s].',
                    is_string($class) ? $class : get_debug_type($class),
                    ImportSessionScope::class,
                ));
            }

            return $app->make($class);
        });

        $this->app->singleton(ImportPresetScope::class, function ($app): ImportPresetScope {
            $class = config('smart-import.authorization.preset_scope', ActorOwnedImportPresetScope::class);

            if (! is_string($class) || ! is_a($class, ImportPresetScope::class, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Configured mapping preset scope [%s] must implement [%s].',
                    is_string($class) ? $class : get_debug_type($class),
                    ImportPresetScope::class,
                ));
            }

            return $app->make($class);
        });

        $this->app->singleton(HubPanelRegistry::class, function (): HubPanelRegistry {
            $panels = config('smart-import.filament.panels', []);

            if (! is_array($panels)) {
                throw new InvalidArgumentException('The [smart-import.filament.panels] configuration value must be a panel ID to Gate ability array.');
            }

            return new HubPanelRegistry($panels);
        });

        $this->app->singleton(ImportAuthorization::class);
        $this->app->singleton(ImportHookDispatcher::class);
        $this->app->singleton(ImportCoordinator::class);
        $this->app->singleton(ImportExportService::class);
        $this->app->singleton(ImportExecutionService::class);
        $this->app->singleton(ImportExecutionDispatcher::class);
        $this->app->singleton(ImportExecutionLifecycle::class);
        $this->app->singleton(ImportProgressService::class);
        $this->app->singleton(ImportRetryService::class);
        $this->app->singleton(ImportRecoveryService::class);
        $this->app->singleton(ImportStagingService::class);
        $this->app->singleton(MappingPresetService::class);
        $this->app->singleton(RowDataProcessor::class);
        $this->app->singleton(StagedRowService::class);
        $this->app->singleton(FileIntakeService::class);
        $this->app->singleton(SpreadsheetReader::class);
        $this->app->singleton(ColumnMapper::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'smart-import');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([DiscoverFilesCommand::class, RecoverImportsCommand::class]);

            $this->publishes([
                __DIR__.'/../config/smart-import.php' => config_path('smart-import.php'),
            ], 'smart-import-config');
        }
    }
}
