<?php

namespace SmartImport\Filament\Pages;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use SmartImport\Services\ImportProgressService;
use UnitEnum;

final class ActiveImportsPage extends ImportHubPage
{
    protected static ?string $slug = 'active-imports';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string | UnitEnum | null $navigationGroup = 'Import Management';

    protected static ?string $navigationLabel = 'Active Imports';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Active Imports';

    protected string $view = 'smart-import::filament.pages.active-imports';

    #[Locked]
    public array $sessions = [];

    #[Locked]
    public bool $shouldPoll = false;

    public function mount(): void
    {
        $this->refreshSessions();
    }

    public function refreshSessions(): void
    {
        $this->sessions = app(ImportProgressService::class)->active($this->actor());
        $this->shouldPoll = $this->sessions !== [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToInbox')
                ->label('Back to inbox')
                ->icon(Heroicon::OutlinedInboxStack)
                ->url(ImportInboxPage::getUrl()),
        ];
    }
}
