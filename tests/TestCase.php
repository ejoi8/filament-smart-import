<?php

namespace SmartImport\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SmartImport\SmartImportServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    protected function getPackageProviders($app): array
    {
        return [
            SmartImportServiceProvider::class,
        ];
    }
}
