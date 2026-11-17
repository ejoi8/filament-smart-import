<?php

namespace SmartImport\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportSession;
use SmartImport\Services\ImportAuthorization;
use UnitEnum;

final class ImportHistoryPage extends ImportHubPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'import-history';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedClock;

    protected static string | UnitEnum | null $navigationGroup = 'Import Management';

    protected static ?string $navigationLabel = 'Import History';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Import History';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->historyQuery())
            ->columns([
                TextColumn::make('original_filename')
                    ->label('File')
                    ->searchable()
                    ->limit(48),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'web' => 'Browser upload',
                        'sftp' => 'SFTP inbox',
                        'cli' => 'Command line',
                        default => str($state)->headline()->toString(),
                    }),
                TextColumn::make('importer_key')
                    ->label('Importer')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('target_model')
                    ->label('Target model')
                    ->searchable()
                    ->limit(36)
                    ->tooltip(fn (ImportSession $record): ?string => $record->target_model)
                    ->placeholder('—'),
                TextColumn::make('created_by_id')
                    ->label('Started by')
                    ->formatStateUsing(fn (?string $state, ImportSession $record): string => $state === null
                        ? 'Unknown'
                        : class_basename((string) $record->created_by_type).' #'.$state)
                    ->searchable()
                    ->placeholder('Unknown'),
                TextColumn::make('tenant_id')
                    ->label('Tenant')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ImportStatus $state): string => str($state->value)->headline()->toString()),
                TextColumn::make('processed')->label('Processed')->numeric(),
                TextColumn::make('created_count')->label('Created')->numeric(),
                TextColumn::make('updated_count')->label('Updated')->numeric(),
                TextColumn::make('skipped_count')->label('Skipped')->numeric(),
                TextColumn::make('failed_count')->label('Failed')->numeric(),
                TextColumn::make('finished_at')->label('Finished')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options($this->statusOptions()),
                SelectFilter::make('source')
                    ->options([
                        'web' => 'Browser upload',
                        'sftp' => 'SFTP inbox',
                        'cli' => 'Command line',
                    ]),
                SelectFilter::make('importer_key')
                    ->label('Importer')
                    ->options(fn (): array => $this->distinctOptions('importer_key')),
                SelectFilter::make('target_model')
                    ->label('Target model')
                    ->options(fn (): array => $this->distinctOptions('target_model')),
                SelectFilter::make('tenant_id')
                    ->label('Tenant')
                    ->options(fn (): array => $this->distinctOptions('tenant_id')),
                SelectFilter::make('initiator')
                    ->label('Started by')
                    ->options(fn (): array => $this->initiatorOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || ! str_contains($value, '|')) {
                            return $query;
                        }

                        [$type, $id] = explode('|', $value, 2);

                        return $query
                            ->where('created_by_type', $type)
                            ->where('created_by_id', $id);
                    }),
                Filter::make('date_range')
                    ->label('Received date')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('through')->label('Through'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['through'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('viewHistory')
                    ->label('View details')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (ImportSession $record): string => ImportHistoryDetailPage::getUrl(['session' => $record->getKey()]))
                    ->visible(fn (ImportSession $record): bool => app(ImportAuthorization::class)
                        ->allows('view', $this->actor(), $record)),
            ])
            ->emptyStateHeading('No completed imports yet')
            ->emptyStateDescription('Finished, failed, and cancelled imports will appear here.')
            ->defaultSort('finished_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activeImports')
                ->label('Active imports')
                ->icon(Heroicon::OutlinedArrowPath)
                ->url(ActiveImportsPage::getUrl()),
            Action::make('backToInbox')
                ->label('Back to inbox')
                ->icon(Heroicon::OutlinedInboxStack)
                ->url(ImportInboxPage::getUrl()),
        ];
    }

    /** @return Builder<ImportSession> */
    private function historyQuery(): Builder
    {
        return app(ImportAuthorization::class)
            ->scopeSessions(ImportSession::query(), $this->actor())
            ->whereIn('status', array_map(
                static fn (ImportStatus $status): string => $status->value,
                [ImportStatus::Completed, ImportStatus::Failed, ImportStatus::Cancelled],
            ));
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return collect([ImportStatus::Completed, ImportStatus::Failed, ImportStatus::Cancelled])
            ->mapWithKeys(static fn (ImportStatus $status): array => [
                $status->value => str($status->value)->headline()->toString(),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private function distinctOptions(string $column): array
    {
        return $this->historyQuery()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->mapWithKeys(static fn (string $value): array => [$value => $value])
            ->all();
    }

    /** @return array<string, string> */
    private function initiatorOptions(): array
    {
        return $this->historyQuery()
            ->whereNotNull('created_by_type')
            ->whereNotNull('created_by_id')
            ->distinct()
            ->orderBy('created_by_type')
            ->orderBy('created_by_id')
            ->get(['created_by_type', 'created_by_id'])
            ->mapWithKeys(static fn (ImportSession $session): array => [
                $session->created_by_type.'|'.$session->created_by_id => $session->created_by_type.' #'.$session->created_by_id,
            ])
            ->all();
    }
}
