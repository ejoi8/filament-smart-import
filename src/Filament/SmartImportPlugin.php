<?php

namespace SmartImport\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use SmartImport\Filament\Pages\ActiveImportsPage;
use SmartImport\Filament\Pages\ImportInboxPage;
use SmartImport\Filament\Pages\ImportHistoryDetailPage;
use SmartImport\Filament\Pages\ImportHistoryPage;
use SmartImport\Filament\Pages\ImportHistoryRowDetailPage;
use SmartImport\Filament\Pages\ImportHistoryRowsPage;
use SmartImport\Filament\Pages\ImportMappingPage;
use SmartImport\Filament\Pages\ImportProgressPage;
use SmartImport\Filament\Pages\ImportReviewPage;
use SmartImport\Services\HubPanelRegistry;

final class SmartImportPlugin implements Plugin
{
    public static function make(): static
    {
        return new static();
    }

    public function getId(): string
    {
        return 'smart-import';
    }

    public function register(Panel $panel): void
    {
        if (! app(HubPanelRegistry::class)->isEnabledFor($panel->getId())) {
            return;
        }

        $panel->pages([
            ImportInboxPage::class,
            ActiveImportsPage::class,
            ImportHistoryPage::class,
            ImportMappingPage::class,
            ImportReviewPage::class,
            ImportProgressPage::class,
            ImportHistoryDetailPage::class,
            ImportHistoryRowsPage::class,
            ImportHistoryRowDetailPage::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
