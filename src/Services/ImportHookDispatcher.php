<?php

namespace SmartImport\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportHooks;
use SmartImport\Data\ImportRowContext;
use SmartImport\Models\ImportSession;
use Throwable;

final class ImportHookDispatcher
{
    public function beforeImport(ImportDefinition $definition, ImportSession $session): void
    {
        if ($definition instanceof ImportHooks) {
            $definition->beforeImport($session);
        }
    }

    public function beforeRowPersist(ImportDefinition $definition, ImportRowContext $context): void
    {
        if ($definition instanceof ImportHooks) {
            $definition->beforeRowPersist($context);
        }
    }

    /** Schedule side effects only after the row and its counters have committed. */
    public function afterRowPersist(
        ImportDefinition $definition,
        ImportRowContext $context,
        ConnectionInterface $connection,
    ): void {
        if (! $definition instanceof ImportHooks) {
            return;
        }

        $connection->afterCommit(function () use ($definition, $context): void {
            try {
                $definition->afterRowPersist($context);
            } catch (Throwable $exception) {
                Log::error('Smart Import after-row hook failed after the row was committed.', [
                    'import_id' => $context->sessionId,
                    'row_id' => $context->rowId,
                    'exception' => $exception::class,
                ]);
            }
        });
    }

    public function afterImport(ImportDefinition $definition, ImportSession $session): void
    {
        if ($definition instanceof ImportHooks) {
            $definition->afterImport($session);
        }
    }
}
