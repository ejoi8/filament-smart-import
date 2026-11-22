<?php

namespace SmartImport\Console\Commands;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use SmartImport\Services\ConsoleActorResolver;
use SmartImport\Services\ImportPreviewService;
use Throwable;

final class PreviewImportCommand extends Command
{
    protected $signature = 'smart-import:preview
        {session : Existing authorized Smart Import session ID}
        {--actor= : Required actor ID resolved through the selected guard}
        {--guard= : Required authentication guard name}
        {--revision= : Required current staging revision to preview}';

    protected $description = 'Generate the current read-only database change preview without writing target records';

    public function handle(ConsoleActorResolver $actors, ImportPreviewService $previews): int
    {
        try {
            $actor = $actors->resolve((string) $this->option('actor'), (string) $this->option('guard'));
            $revision = filter_var($this->option('revision'), FILTER_VALIDATE_INT);

            if (! is_int($revision) || $revision < 1) {
                throw new InvalidArgumentException('Provide a positive current staging revision with --revision.');
            }

            $result = $previews->generateOrQueue((string) $this->argument('session'), $actor, $revision);

            $this->line(json_encode([
                'ok' => true,
                'session_id' => (string) $this->argument('session'),
                'queued' => $result['queued'],
                'already_queued' => $result['already_queued'],
                'summary' => $result['summary'],
                'writes_targets' => false,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (AuthorizationException) {
            return $this->failure('The selected actor is not authorized to preview this import.', 3);
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 2);
        } catch (Throwable) {
            return $this->failure('The preview could not be generated. Check the session and queue configuration.', self::FAILURE);
        }
    }

    private function failure(string $message, int $code): int
    {
        $this->line(json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $code;
    }
}
