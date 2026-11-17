<?php

namespace SmartImport\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Builder;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportSession;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportAuthorization;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImporterRegistry;
use UnitEnum;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportInboxPage extends ImportHubPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'import-inbox';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string | UnitEnum | null $navigationGroup = 'Import Management';

    protected static ?string $navigationLabel = 'Import Inbox';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Import Inbox';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(ImportAuthorization::class)
                ->scopeSessions(ImportSession::query(), $this->actor())
                ->where('intake_status', '!=', IntakeStatus::Archived->value))
            ->columns([
                TextColumn::make('original_filename')
                    ->label('File')
                    ->searchable()
                    ->limit(48)
                    ->tooltip(fn (ImportSession $record): string => $record->original_filename),
                TextColumn::make('source')->badge(),
                TextColumn::make('file_type')->label('Type')->badge(),
                TextColumn::make('possible_duplicate_of')
                    ->label('Possible duplicate')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : 'Review duplicate')
                    ->placeholder('—'),
                TextColumn::make('file_size')
                    ->label('Size')
                    ->formatStateUsing(fn (mixed $state): string => number_format(((int) $state) / 1024, 1).' KB'),
                TextColumn::make('file_hash')
                    ->label('SHA-256')
                    ->limit(14)
                    ->tooltip(fn (ImportSession $record): string => $record->file_hash),
                TextColumn::make('importer_key')
                    ->label('Importer')
                    ->formatStateUsing(function (?string $state): string {
                        if ($state === null) {
                            return 'Unassigned';
                        }

                        $registry = app(ImporterRegistry::class);

                        return $registry->has($state) ? $registry->get($state)->label() : 'Unavailable';
                    }),
                TextColumn::make('intake_status')
                    ->label('Inbox status')
                    ->badge()
                    ->formatStateUsing(fn (IntakeStatus $state): string => str($state->value)->headline()->toString()),
                TextColumn::make('error_summary')
                    ->label('Details')
                    ->limit(48)
                    ->tooltip(fn (ImportSession $record): ?string => $record->error_summary)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Import status')
                    ->badge()
                    ->placeholder('Not staged')
                    ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : str($state->value)->headline()->toString()),
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('assignImporter')
                    ->label('Assign importer')
                    ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                    ->schema([
                        Select::make('importer_key')
                            ->label('Importer')
                            ->options(fn (): array => $this->authorizedImporterOptions())
                            ->required()
                            ->searchable(),
                    ])
                    ->visible(fn (ImportSession $record): bool => $this->canAssign($record) && $this->authorizedImporterOptions() !== [])
                    ->action(function (ImportSession $record, array $data): void {
                        app(ImportCoordinator::class)->assignImporter(
                            (string) $record->getKey(),
                            (string) ($data['importer_key'] ?? ''),
                            $this->actor(),
                        );

                        Notification::make()->success()->title('Importer assigned')->send();
                    }),
                Action::make('configureMapping')
                    ->label('Configure mapping')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->url(fn (ImportSession $record): string => ImportMappingPage::getUrl(['session' => $record->getKey()]))
                    ->visible(fn (ImportSession $record): bool => $this->canConfigure($record)),
                Action::make('viewProgress')
                    ->label('View progress')
                    ->icon(Heroicon::OutlinedChartBar)
                    ->url(fn (ImportSession $record): string => ImportProgressPage::getUrl(['session' => $record->getKey()]))
                    ->visible(fn (ImportSession $record): bool => $record->status !== null),
                Action::make('reject')
                    ->label('Reject file')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(1000)
                            ->rows(3),
                    ])
                    ->visible(fn (ImportSession $record): bool => $this->canReject($record))
                    ->action(function (ImportSession $record, array $data): void {
                        try {
                            app(FileIntakeService::class)->reject(
                                (string) $record->getKey(),
                                (string) ($data['reason'] ?? ''),
                                $this->actor(),
                            );
                        } catch (AuthorizationException) {
                            Notification::make()->danger()->title('Rejection is not authorized')->send();

                            return;
                        } catch (LockTimeoutException) {
                            Notification::make()->warning()->title('Another intake update is still running')->body('Try again shortly.')->send();

                            return;
                        } catch (DomainException|InvalidArgumentException|RuntimeException $exception) {
                            Notification::make()->danger()->title('File could not be rejected')->body($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('File rejected')->send();
                    }),
                Action::make('archive')
                    ->label('Archive file')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->requiresConfirmation()
                    ->visible(fn (ImportSession $record): bool => $this->canArchive($record))
                    ->action(function (ImportSession $record): void {
                        try {
                            app(FileIntakeService::class)->archive((string) $record->getKey(), $this->actor());
                        } catch (AuthorizationException) {
                            Notification::make()->danger()->title('Archiving is not authorized')->send();

                            return;
                        } catch (LockTimeoutException) {
                            Notification::make()->warning()->title('Another intake update is still running')->body('Try again shortly.')->send();

                            return;
                        } catch (DomainException|InvalidArgumentException|RuntimeException $exception) {
                            Notification::make()->danger()->title('File could not be archived')->body($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('File archived')->send();
                    }),
            ])
            ->emptyStateHeading(fn (): string => $this->emptyStateHeading())
            ->emptyStateDescription('Upload a CSV, XLSX, or XLS file, or assign an importer to a discovered file. Files are reviewed before any rows are staged.')
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activeImports')
                ->label('Active imports')
                ->icon(Heroicon::OutlinedArrowPath)
                ->url(ActiveImportsPage::getUrl()),
            Action::make('importHistory')
                ->label('Import history')
                ->icon(Heroicon::OutlinedClock)
                ->url(ImportHistoryPage::getUrl()),
            Action::make('discover')
                ->label('Discover files')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->visible(fn (): bool => app(Gate::class)->forUser($this->actor())->allows('create', ImportSession::class))
                ->action(function (): void {
                    try {
                        $sessions = app(FileIntakeService::class)->discoverInbox($this->actor());
                    } catch (AuthorizationException) {
                        Notification::make()->danger()->title('Discovery is not authorized')->send();

                        return;
                    } catch (LockTimeoutException) {
                        Notification::make()->warning()->title('Another discovery is still running')->body('Try again shortly.')->send();

                        return;
                    } catch (InvalidArgumentException|RuntimeException $exception) {
                        Notification::make()->danger()->title('Discovery could not run')->body($exception->getMessage())->send();

                        return;
                    }

                    $count = count($sessions);

                    Notification::make()
                        ->success()
                        ->title('Inbox discovery complete')
                        ->body($count === 0 ? 'No new supported files were found.' : sprintf('%d file(s) added to the inbox.', $count))
                        ->send();
                }),
            Action::make('upload')
                ->label('Upload file')
                ->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible(fn (): bool => app(Gate::class)->forUser($this->actor())->allows('create', ImportSession::class))
                ->schema([
                    FileUpload::make('file')
                        ->label('CSV, XLSX, or XLS file')
                        ->required()
                        ->storeFiles(false)
                        ->acceptedFileTypes([
                            'application/csv',
                            'application/cdfv2',
                            'application/octet-stream',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/zip',
                            'application/x-cfb',
                            'application/x-ole-storage',
                            'text/csv',
                            'text/plain',
                        ])
                        ->maxSize(max(1, (int) floor((int) config('smart-import.uploads.max_file_size_bytes', 10 * 1024 * 1024) / 1024))),
                ])
                ->action(function (array $data): void {
                    $file = $data['file'] ?? null;

                    if (! $file instanceof UploadedFile) {
                        throw new InvalidArgumentException('Select one supported spreadsheet file.');
                    }

                    try {
                        app(FileIntakeService::class)->upload($file, $this->actor());
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->danger()->title('File could not be uploaded')->body($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('File added to the import inbox')->send();
                }),
        ];
    }

    private function canAssign(ImportSession $record): bool
    {
        return $record->status === null
            && ! in_array($record->intake_status, [IntakeStatus::Rejected, IntakeStatus::Archived], true)
            && app(Gate::class)->forUser($this->actor())->allows('update', $record);
    }

    private function canConfigure(ImportSession $record): bool
    {
        if (! $this->canAssign($record) || $record->importer_key === null) {
            return false;
        }

        $registry = app(ImporterRegistry::class);
        $gate = app(Gate::class)->forUser($this->actor());

        return $registry->has($record->importer_key)
            && $gate->allows('update', $record)
            && $gate->allows('useImporter', $registry->get($record->importer_key));
    }

    private function canReject(ImportSession $record): bool
    {
        return in_array($record->status, [null, ImportStatus::ReviewReady], true)
            && ! in_array($record->intake_status, [IntakeStatus::Rejected, IntakeStatus::Archived], true)
            && app(Gate::class)->forUser($this->actor())->allows('update', $record);
    }

    private function canArchive(ImportSession $record): bool
    {
        return $record->intake_status !== IntakeStatus::Archived
            && ! in_array($record->status, [ImportStatus::Staging, ImportStatus::Queued, ImportStatus::Importing, ImportStatus::Cancelling], true)
            && app(Gate::class)->forUser($this->actor())->allows('update', $record);
    }

    /** @return array<string, string> */
    private function authorizedImporterOptions(): array
    {
        $actor = $this->actor();
        $gate = app(Gate::class)->forUser($actor);
        $options = [];

        foreach (app(ImporterRegistry::class)->all() as $definition) {
            if ($gate->allows('useImporter', $definition)) {
                $options[$definition->key()] = $definition->label();
            }
        }

        return $options;
    }

    private function emptyStateHeading(): string
    {
        $registry = app(ImporterRegistry::class);

        if ($registry->all() === []) {
            return 'No importers are registered';
        }

        return $this->authorizedImporterOptions() === []
            ? 'No importers are available to your account'
            : 'No files in the import inbox';
    }
}
