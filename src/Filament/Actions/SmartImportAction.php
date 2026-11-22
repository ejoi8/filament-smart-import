<?php

namespace SmartImport\Filament\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use SmartImport\Filament\Pages\ImportInboxPage;
use SmartImport\Services\ImportAuthorization;
use SmartImport\Services\ImporterRegistry;

/** A thin Resource shortcut to the shared Import Inbox workflow. */
final class SmartImportAction
{
    public static function make(string $importerKey, ?string $name = null): Action
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,99}\z/D', $importerKey) !== 1) {
            throw new InvalidArgumentException('Smart Import Resource actions require a valid registered importer key.');
        }

        $actionName = $name ?? 'smartImport'.str_replace(['-', '_'], '', ucwords($importerKey, '-_'));

        return Action::make($actionName)
            ->label(fn (): string => self::label($importerKey))
            ->url(fn (): string => ImportInboxPage::getUrl(['importer' => $importerKey]))
            ->visible(fn (): bool => self::isAuthorized($importerKey));
    }

    private static function isAuthorized(string $importerKey): bool
    {
        $actor = Filament::auth()->user();
        $panel = Filament::getCurrentPanel();

        if (! $actor instanceof Authenticatable || $panel === null) {
            return false;
        }

        try {
            $authorization = app(ImportAuthorization::class);
            $authorization->authorizeHubPanel($panel->getId(), $actor);
            $authorization->authorize('useImporter', $actor, app(ImporterRegistry::class)->get($importerKey));

            return true;
        } catch (AuthorizationException | InvalidArgumentException) {
            return false;
        }
    }

    private static function label(string $importerKey): string
    {
        try {
            return 'Import: '.app(ImporterRegistry::class)->get($importerKey)->label();
        } catch (InvalidArgumentException) {
            return 'Open Import Hub';
        }
    }
}
