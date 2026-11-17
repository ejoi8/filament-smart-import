<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Data\ImportRowContext;
use SmartImport\Enums\ImportWriteAction;
use SmartImport\Models\ImportSession;
use SmartImport\Services\ImportHookDispatcher;
use SmartImport\Tests\Fixtures\HookedPreviewImportDefinition;
use SmartImport\Tests\TestCase;

class ImportHookDispatcherTest extends TestCase
{
    #[Test]
    public function importer_lifecycle_hooks_are_optional_and_after_row_runs_only_through_after_commit(): void
    {
        $definition = new HookedPreviewImportDefinition();
        $definition::$calls = [];
        $dispatcher = $this->app->make(ImportHookDispatcher::class);
        $context = new ImportRowContext(
            'session-1',
            'preview-contacts',
            '1',
            3,
            12,
            2,
            ImportWriteAction::Create,
            ImportTargetModel::class,
            '42',
            ['email' => 'new@example.test'],
            4,
        );
        $this->assertSame('session-1:4:12', $context->idempotencyKey());
        $connection = Mockery::mock(ConnectionInterface::class);
        $afterCommit = null;
        $connection->shouldReceive('afterCommit')
            ->once()
            ->with(Mockery::on(function (callable $callback) use (&$afterCommit): bool {
                $afterCommit = $callback;
                return true;
            }));

        $dispatcher->beforeRowPersist($definition, $context);
        $dispatcher->afterRowPersist($definition, $context, $connection);

        $this->assertSame([
            ['hook' => 'before_row', 'session_id' => 'session-1', 'row_id' => 12],
        ], HookedPreviewImportDefinition::$calls);
        $afterCommit();

        $this->assertSame([
            ['hook' => 'before_row', 'session_id' => 'session-1', 'row_id' => 12],
            ['hook' => 'after_row', 'session_id' => 'session-1', 'row_id' => 12],
        ], HookedPreviewImportDefinition::$calls);
    }

    #[Test]
    public function definitions_without_optional_hooks_are_accepted(): void
    {
        $dispatcher = $this->app->make(ImportHookDispatcher::class);
        $definition = new \SmartImport\Tests\Fixtures\PreviewImportDefinition();
        $context = new ImportRowContext(
            'session-1',
            $definition->key(),
            $definition->version(),
            1,
            1,
            2,
            ImportWriteAction::Create,
            $definition->targetModel(),
            null,
            ['email' => 'new@example.test'],
        );

        $dispatcher->beforeRowPersist($definition, $context);

        $this->assertTrue(true);
    }

    #[Test]
    public function optional_import_level_hooks_can_be_called_by_the_shared_lifecycle_coordinator(): void
    {
        $definition = new HookedPreviewImportDefinition();
        $definition::$calls = [];
        $session = new ImportSession();
        $session->setAttribute($session->getKeyName(), 'session-1');
        $dispatcher = $this->app->make(ImportHookDispatcher::class);

        $dispatcher->beforeImport($definition, $session);
        $dispatcher->afterImport($definition, $session);

        $this->assertSame([
            ['hook' => 'before_import', 'session_id' => 'session-1'],
            ['hook' => 'after_import', 'session_id' => 'session-1'],
        ], HookedPreviewImportDefinition::$calls);
    }
}
