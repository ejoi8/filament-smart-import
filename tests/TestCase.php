<?php

namespace SmartImport\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SmartImport\SmartImportServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
        $app['config']->set('queue.default', 'smart-import-testing');
        $app['config']->set('queue.connections.smart-import-testing.driver', 'database');
    }

    protected function getPackageProviders($app): array
    {
        return [
            SmartImportServiceProvider::class,
        ];
    }
}
