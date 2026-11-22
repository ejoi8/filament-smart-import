<?php

namespace SmartImport\Console\Commands;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use SmartImport\Services\ConsoleActorResolver;
use SmartImport\Services\ImportPreviewService;
use Throwable;

final class ConfirmImportCommand extends Command
{
    protected $signature = 'smart-import:confirm
        {session : Existing authorized Smart Import session ID}
        {--actor= : Required actor ID resolved through the selected guard}
        {--guard= : Required authentication guard name}
        {--revision= : Required current reviewed staging revision}
        {--confirm : Explicitly queue this current reviewed import}';

    protected $description = 'Confirm the explicitly reviewed revision and queue its import execution';

    public function handle(ConsoleActorResolver $actors, ImportPreviewService $previews): int
    {
        if (! $this->option('confirm')) {
            return $this->failure('No changes were queued. Pass --confirm after reviewing the current preview.', 2);
        }

        try {
            $actor = $actors->resolve((string) $this->option('actor'), (string) $this->option('guard'));
            $revision = filter_var($this->option('revision'), FILTER_VALIDATE_INT);

            if (! is_int($revision) || $revision < 1) {
                throw new InvalidArgumentException('Provide the current positive staging revision with --revision.');
            }

            $session = $previews->confirm((string) $this->argument('session'), $actor, $revision);

            $this->line(json_encode([
                'ok' => true,
                'session_id' => (string) $session->getKey(),
                'status' => $session->status?->value,
                'attempt' => $session->current_attempt,
                'confirmed_revision' => $session->confirmed_revision,
                'execution_total' => $session->execution_total,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (AuthorizationException) {
            return $this->failure('The selected actor is not authorized to confirm this import.', 3);
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 2);
        } catch (Throwable) {
            return $this->failure('The import could not be queued. Check the current preview and queue configuration.', self::FAILURE);
        }
    }

    private function failure(string $message, int $code): int
    {
        $this->line(json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $code;
    }
}
