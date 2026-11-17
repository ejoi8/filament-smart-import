<?php

namespace SmartImport\Filament\Pages;

use Filament\Actions\Action;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportRow;
use SmartImport\Models\ImportSession;
use SmartImport\Services\ImportCoordinator;

final class ImportHistoryRowsPage extends ImportHubPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'imports/{session}/history/rows';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Import row results';

    protected string $view = 'smart-import::filament.pages.import-history-rows';

    #[Locked]
    public string $sessionId;

    public function mount(string $session): void
    {
        $this->sessionId = $session;
        $this->authorizedHistorySession();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->authorizedHistorySession()->rows()->getQuery())
            ->columns([
                TextColumn::make('source_sheet')->label('Sheet')->placeholder('—'),
                TextColumn::make('source_row_number')->label('Source row')->sortable(),
                TextColumn::make('validation_status')
                    ->label('Validation')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('execution_status')
                    ->label('Result')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('change_type')
                    ->label('Target match')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : str($state)->headline()->toString())
                    ->placeholder('—'),
                TextColumn::make('resolved_target_id')
                    ->label('Target ID')
                    ->formatStateUsing(fn (?string $state, ImportRow $record): ?string => $this->targetId($record) ?? $state)
                    ->limit(24)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('validation_status')->options([
                    'pending' => 'Pending',
                    'valid' => 'Valid',
                    'invalid' => 'Invalid',
                    'warning' => 'Warning',
                ]),
                SelectFilter::make('execution_status')->label('Result')->options([
                    'pending' => 'Pending',
                    'created' => 'Created',
                    'updated' => 'Updated',
                    'skipped' => 'Skipped',
                    'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                Action::make('viewRow')
                    ->label('View row')
                    ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                    ->url(fn (ImportRow $record): string => ImportHistoryRowDetailPage::getUrl([
                        'session' => $this->sessionId,
                        'row' => $record->getKey(),
                    ])),
            ])
            ->emptyStateHeading('No rows recorded')
            ->emptyStateDescription('This import does not have staged row records.')
            ->defaultSort('source_row_number')
            ->paginated([10, 25, 50]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToHistory')
                ->label('Back to import details')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->url(ImportHistoryDetailPage::getUrl(['session' => $this->sessionId])),
            Action::make('backToHistoryList')
                ->label('Import history')
                ->icon(Heroicon::OutlinedClock)
                ->url(ImportHistoryPage::getUrl()),
        ];
    }

    private function authorizedHistorySession(): ImportSession
    {
        $session = app(ImportCoordinator::class)->get($this->sessionId, $this->actor());

        abort_unless(in_array($session->status, [ImportStatus::Completed, ImportStatus::Failed, ImportStatus::Cancelled], true), 404);

        return $session;
    }

    private function targetId(ImportRow $row): ?string
    {
        $outcomes = $row->execution_history ?? [];
        $lastOutcome = $outcomes === [] ? [] : $outcomes[array_key_last($outcomes)];

        return is_array($lastOutcome) && is_scalar($lastOutcome['target_id'] ?? null)
            ? (string) $lastOutcome['target_id']
            : null;
    }
}
