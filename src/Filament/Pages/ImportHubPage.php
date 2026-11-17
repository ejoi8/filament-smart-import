<?php

namespace SmartImport\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use SmartImport\Services\ImportAuthorization;

abstract class ImportHubPage extends Page
{
    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        $panel = Filament::getCurrentPanel();

        if (! $actor instanceof Authenticatable || $panel === null) {
            return false;
        }

        try {
            app(ImportAuthorization::class)->authorizeHubPanel($panel->getId(), $actor);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    protected function actor(): Authenticatable
    {
        $actor = Filament::auth()->user();

        abort_unless($actor instanceof Authenticatable, 401);
        app(ImportAuthorization::class)->authorizeHubPanel(Filament::getCurrentOrDefaultPanel()->getId(), $actor);

        return $actor;
    }
}
