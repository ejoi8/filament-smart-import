<?php

namespace SmartImport\Filament\Pages;

use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Models\ImportSession;
use SmartImport\Services\ColumnMapper;
use SmartImport\Services\ImportAuthorization;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImporterRegistry;
use SmartImport\Services\ImportStagingService;
use SmartImport\Services\SpreadsheetReader;
use Illuminate\Validation\ValidationException;

final class ImportMappingPage extends ImportHubPage
{
    protected static ?string $slug = 'imports/{session}/mapping';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Map import columns';

    protected string $view = 'smart-import::filament.pages.import-mapping';

    #[Locked]
    public string $sessionId;

    #[Locked]
    public string $filename = '';

    #[Locked]
    public string $importerLabel = '';

    #[Locked]
    public array $sheetOptions = [];

    #[Locked]
    public array $headerCells = [];

    #[Locked]
    public array $previewRows = [];

    #[Locked]
    public array $fieldOptions = [];

    #[Locked]
    public string $previewMessage = '';

    public int $sheetIndex = 0;

    public string $headerRowValue = '1';

    /** @var array<int, string> */
    public array $mapping = [];

    public function mount(string $session): void
    {
        $this->sessionId = $session;
        $this->refreshPreview();
    }

    public function content(Schema $schema): Schema
    {
        return $schema;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToInbox')
                ->label('Back to inbox')
                ->url(ImportInboxPage::getUrl()),
        ];
    }

    public function updatedSheetIndex(): void
    {
        $this->headerRowValue = '1';
        $this->refreshPreview();
    }

    public function updatedHeaderRowValue(): void
    {
        try {
            $this->refreshPreview();
        } catch (InvalidArgumentException) {
            $this->headerRowValue = 'none';
            $this->refreshPreview();
        }
    }

    public function startStaging(): void
    {
        [$session, $definition] = $this->authorizedContext();
        $headerRow = $this->selectedHeaderRow();
        $mapping = [];

        foreach (array_keys($this->headerCells) as $sourceColumn) {
            $choice = $this->mapping[$sourceColumn] ?? '';

            if ($choice === 'ignore:') {
                $mapping[$sourceColumn] = null;

                continue;
            }

            if (! is_string($choice) || ! str_starts_with($choice, 'field:') || strlen($choice) === 6) {
                throw ValidationException::withMessages([
                    "mapping.{$sourceColumn}" => 'Choose an importer field or explicitly ignore this source column.',
                ]);
            }

            $mapping[$sourceColumn] = substr($choice, 6);
        }

        try {
            app(ImportStagingService::class)->start(
                (string) $session->getKey(),
                $this->actor(),
                $definition->key(),
                $this->sheetIndex,
                $headerRow,
                $mapping,
            );
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (InvalidArgumentException | DomainException $exception) {
            Notification::make()
                ->danger()
                ->title('Staging could not be started')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Validation staging queued')
            ->body('The file will be checked and staged for review. Target records have not been changed.')
            ->send();

        $this->redirect(ImportInboxPage::getUrl(), navigate: true);
    }

    private function refreshPreview(): void
    {
        [$session, $definition] = $this->authorizedContext();
        $sheets = app(SpreadsheetReader::class)->listSheets($session);

        $mappableSheets = array_filter($sheets, static fn (array $sheet): bool => $sheet['columns'] > 0);

        if ($mappableSheets === []) {
            throw new InvalidArgumentException('The file does not contain a readable worksheet.');
        }

        if (! array_key_exists($this->sheetIndex, $mappableSheets)) {
            $this->sheetIndex = (int) array_key_first($mappableSheets);
        }

        $sheet = $sheets[$this->sheetIndex];
        $this->sheetOptions = [];

        foreach ($mappableSheets as $candidate) {
            $this->sheetOptions[$candidate['index']] = sprintf('%s (%d rows)', $candidate['name'], $candidate['rows']);
        }

        $sampleLimit = max(1, (int) config('smart-import.reading.preview_rows', 20));
        $sampleRows = min($sampleLimit, max(1, $sheet['rows']));
        $preview = app(SpreadsheetReader::class)->preview($session, $this->sheetIndex, 1, $sampleRows);
        $this->previewRows = $preview['rows'];
        $this->previewMessage = '';
        $headerRow = $this->selectedHeaderRow();

        if ($headerRow === null) {
            $headers = array_fill_keys(range(1, $sheet['columns']), null);
        } else {
            $header = collect($this->previewRows)->firstWhere('row_number', $headerRow);

            if (! is_array($header)) {
                $headers = array_fill_keys(range(1, $sheet['columns']), null);
                $this->previewMessage = 'That row is blank or outside the sample. Choose another header row or select “No header row”.';
            } else {
                $headers = $header['values'];
            }
        }

        $suggestions = app(ColumnMapper::class)->suggest($definition, $headers);
        $this->headerCells = [];
        $this->mapping = [];

        foreach ($headers as $sourceColumn => $headerValue) {
            $this->headerCells[$sourceColumn] = $headerValue;
            $suggestedField = $suggestions['mapping'][$sourceColumn] ?? null;
            $this->mapping[$sourceColumn] = $suggestedField === null ? '' : 'field:'.$suggestedField;
        }

        $this->fieldOptions = ['ignore:' => 'Ignore this column'];

        foreach ($definition->fields() as $field) {
            $this->fieldOptions['field:'.$field->name] = $field->label.($field->required ? ' (required)' : '');
        }
    }

    /** @return array{ImportSession, \SmartImport\Contracts\ImportDefinition} */
    private function authorizedContext(): array
    {
        $session = app(ImportCoordinator::class)->get($this->sessionId, $this->actor());
        app(ImportAuthorization::class)->authorize('update', $this->actor(), $session);

        if ($session->importer_key === null || $session->status !== null) {
            abort(404);
        }

        if (in_array($session->intake_status, [IntakeStatus::Rejected, IntakeStatus::Archived], true)) {
            abort(404);
        }

        $definition = app(ImporterRegistry::class)->get($session->importer_key);
        app(ImportAuthorization::class)->authorize('useImporter', $this->actor(), $definition);

        if ($session->importer_version !== $definition->version()) {
            abort(409, 'The importer definition changed. Reassign it before configuring the mapping.');
        }

        $this->filename = $session->original_filename;
        $this->importerLabel = $definition->label();

        return [$session, $definition];
    }

    private function selectedHeaderRow(): ?int
    {
        if ($this->headerRowValue === 'none') {
            return null;
        }

        if (preg_match('/^[1-9][0-9]*$/D', $this->headerRowValue) !== 1) {
            throw new InvalidArgumentException('Choose a valid header row or select no header row.');
        }

        return (int) $this->headerRowValue;
    }
}
