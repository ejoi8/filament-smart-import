<?php

namespace SmartImport\Filament\Pages;

use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use SmartImport\Services\ImportPreviewService;

final class ImportHistoryRowDetailPage extends ImportHubPage
{
    protected static ?string $slug = 'imports/{session}/history/rows/{row}';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Import row details';

    protected string $view = 'smart-import::filament.pages.import-history-row-detail';

    #[Locked]
    public string $sessionId;

    #[Locked]
    public int $rowId;

    #[Locked]
    public array $rowDetail = [];

    public function mount(string $session, int $row): void
    {
        $this->sessionId = $session;
        $this->rowId = $row;
        $this->rowDetail = app(ImportPreviewService::class)->historyRow($this->sessionId, $this->rowId, $this->actor());
    }

    public function content(Schema $schema): Schema
    {
        return $schema;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToRows')
                ->label('Back to row results')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->url(ImportHistoryRowsPage::getUrl(['session' => $this->sessionId])),
            Action::make('backToHistory')
                ->label('Import details')
                ->icon(Heroicon::OutlinedDocumentText)
                ->url(ImportHistoryDetailPage::getUrl(['session' => $this->sessionId])),
        ];
    }
}
