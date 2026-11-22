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
use SmartImport\Services\ImportOptionResolver;
use SmartImport\Services\MappingPresetService;
use SmartImport\Services\SpreadsheetReader;
use SmartImport\Contracts\ImportOptionProvider;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

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
    public array $sheetNames = [];

    #[Locked]
    public array $headerCells = [];

    #[Locked]
    public ?array $headerSuggestion = null;

    /** @var array<int, string> */
    #[Locked]
    public array $suggestedMappings = [];

    #[Locked]
    public array $previewRows = [];

    #[Locked]
    public array $fieldOptions = [];

    #[Locked]
    public string $previewMessage = '';

    #[Locked]
    public bool $isCsv = false;

    #[Locked]
    public int $sheetRowCount = 0;

    #[Locked]
    public array $importerOptionDefinitions = [];

    #[Locked]
    public array $presetSelectOptions = [];

    #[Locked]
    public array $presetCompatibility = [];

    #[Locked]
    public string $processingSummary = '';

    public int $sheetIndex = 0;

    public string $headerRowValue = '1';

    public string $customHeaderRowValue = '21';

    public string $csvDelimiterValue = 'auto';

    public string $csvEncodingValue = Csv::GUESS_ENCODING;

    /** @var array<string, string> */
    public array $importerOptions = [];

    public string $selectedPresetId = '';

    public string $presetName = '';

    public string $presetMessage = '';

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
        if ($this->headerRowValue === 'custom') {
            $this->previewMessage = 'Enter a row number to read that header row.';

            return;
        }

        try {
            $this->refreshPreview();
        } catch (InvalidArgumentException) {
            $this->headerRowValue = 'none';
            $this->refreshPreview();
        }
    }

    public function updatedCustomHeaderRowValue(): void
    {
        try {
            $this->refreshPreview();
        } catch (InvalidArgumentException $exception) {
            $this->previewMessage = $exception->getMessage();
        }
    }

    public function useDetectedHeaderRow(): void
    {
        [, $definition] = $this->authorizedContext();
        $suggestion = app(ColumnMapper::class)->detectHeaderRow($definition, $this->previewRows);

        if ($suggestion === null) {
            $this->previewMessage = 'No confident header row was found in the current sample. Choose the row manually.';

            return;
        }

        $this->headerRowValue = (string) $suggestion['row_number'];
        $this->refreshPreview();
    }

    public function updatedCsvDelimiterValue(): void
    {
        $this->refreshPreview(preserveMapping: true);
    }

    public function updatedCsvEncodingValue(): void
    {
        $this->refreshPreview(preserveMapping: true);
    }

    public function updatedImporterOptions(): void
    {
        $this->refreshProcessingSummary();
    }

    public function updatedSelectedPresetId(): void
    {
        $this->refreshPresetCompatibility();
    }

    public function applySelectedPreset(): void
    {
        $resolved = $this->resolveSelectedPreset();

        if (! $resolved['compatible']) {
            $this->presetMessage = $this->presetIncompatibilityMessage((string) $resolved['incompatibility']);

            return;
        }

        $this->mapping = [];

        foreach ($resolved['mapping'] ?? [] as $sourceColumn => $fieldName) {
            $this->mapping[$sourceColumn] = $fieldName === null ? 'ignore:' : 'field:'.$fieldName;
        }

        $this->importerOptions = $resolved['options']['importer'] ?? [];
        $this->presetMessage = sprintf('Preset "%s" applied. Review the mapping before staging.', $resolved['name']);
        $this->refreshProcessingSummary();
    }

    public function saveMappingPreset(): void
    {
        [$session, $definition] = $this->authorizedContext();
        $mapping = $this->selectedMapping();
        $options = [
            'reader' => $this->selectedReaderOptions(),
            'importer' => $this->resolvedImporterOptions(),
        ];

        try {
            app(MappingPresetService::class)->save(
                $definition->key(),
                trim($this->presetName),
                $this->sheetIndex,
                $this->sheetNames[$this->sheetIndex] ?? 'Sheet',
                $this->selectedHeaderRow(),
                $this->headerCells,
                $mapping,
                $this->actor(),
                $options,
            );
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (InvalidArgumentException | DomainException $exception) {
            Notification::make()->danger()->title('Mapping preset could not be saved')->body($exception->getMessage())->send();

            return;
        }

        $this->presetName = '';
        $this->presetMessage = 'Mapping preset saved for this importer and source header.';
        Notification::make()->success()->title('Mapping preset saved')->send();
        $this->refreshPresetOptions($definition->key());
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
            $stagingSession = app(ImportStagingService::class)->start(
                (string) $session->getKey(),
                $this->actor(),
                $definition->key(),
                $this->sheetIndex,
                $headerRow,
                $mapping,
                [
                    ...$this->selectedReaderOptions(),
                    'importer_options' => $this->resolvedImporterOptions(),
                ],
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
            ->body('Progress is saved. If staging stays at zero, check that a queue worker is running for the configured queue.')
            ->send();

        $this->redirect(ImportProgressPage::getUrl(['session' => $stagingSession->getKey()]), navigate: true);
    }

    private function refreshPreview(bool $preserveMapping = false): void
    {
        [$session, $definition] = $this->authorizedContext();
        $readerOptions = $this->selectedReaderOptions();
        $reader = app(SpreadsheetReader::class);
        $sheets = $reader->listSheets($session, $readerOptions['csv_delimiter'], $readerOptions['csv_encoding']);

        $mappableSheets = array_filter($sheets, static fn (array $sheet): bool => $sheet['columns'] > 0);

        if ($mappableSheets === []) {
            throw new InvalidArgumentException('The file does not contain a readable worksheet.');
        }

        if (! array_key_exists($this->sheetIndex, $mappableSheets)) {
            $this->sheetIndex = (int) array_key_first($mappableSheets);
        }

        $sheet = $sheets[$this->sheetIndex];
        $this->sheetRowCount = (int) $sheet['rows'];
        $this->isCsv = strtolower((string) $session->file_type) === 'csv';
        $this->sheetOptions = [];
        $this->sheetNames = [];

        foreach ($mappableSheets as $candidate) {
            $this->sheetOptions[$candidate['index']] = sprintf('%s (%d rows)', $candidate['name'], $candidate['rows']);
            $this->sheetNames[$candidate['index']] = $candidate['name'];
        }

        $sampleLimit = max(1, (int) config('smart-import.reading.preview_rows', 20));
        $sampleRows = min($sampleLimit, max(1, $sheet['rows']));
        $preview = $reader->preview(
            $session,
            $this->sheetIndex,
            1,
            $sampleRows,
            csvDelimiter: $readerOptions['csv_delimiter'],
            csvEncoding: $readerOptions['csv_encoding'],
        );
        $this->previewRows = $preview['rows'];
        $this->headerSuggestion = app(ColumnMapper::class)->detectHeaderRow($definition, $this->previewRows);
        $this->previewMessage = '';
        $headerRow = $this->selectedHeaderRow();
        $previousMapping = $preserveMapping ? $this->mapping : [];

        if ($headerRow === null) {
            $headers = array_fill_keys(range(1, $sheet['columns']), null);
        } else {
            $header = collect($this->previewRows)->firstWhere('row_number', $headerRow);

            if (is_array($header)) {
                $headers = $header['values'];
            } else {
                $headerPreview = $reader->preview(
                    $session,
                    $this->sheetIndex,
                    $headerRow,
                    1,
                    null,
                    $readerOptions['csv_delimiter'],
                    $readerOptions['csv_encoding'],
                );
                $headerRowData = collect($headerPreview['rows'])->firstWhere('row_number', $headerRow);
                $headers = is_array($headerRowData) ? $headerRowData['values'] : null;

                if ($headers === null) {
                    $headers = array_fill_keys(range(1, $sheet['columns']), null);
                    $this->previewMessage = 'That row is blank. Choose another header row or select “No header row”.';
                }
            }
        }

        $suggestions = app(ColumnMapper::class)->suggest($definition, $headers);
        $validChoices = ['ignore:'];
        $this->suggestedMappings = [];

        foreach ($suggestions['mapping'] as $sourceColumn => $suggestedField) {
            $this->suggestedMappings[$sourceColumn] = 'field:'.$suggestedField;
        }

        foreach ($definition->fields() as $field) {
            $validChoices[] = 'field:'.$field->name;
        }

        $this->headerCells = [];
        $this->mapping = [];

        foreach ($headers as $sourceColumn => $headerValue) {
            $this->headerCells[$sourceColumn] = $headerValue;
            $suggestedField = $suggestions['mapping'][$sourceColumn] ?? null;
            $previousChoice = $previousMapping[$sourceColumn] ?? null;
            $this->mapping[$sourceColumn] = is_string($previousChoice) && in_array($previousChoice, $validChoices, true)
                ? $previousChoice
                : ($suggestedField === null ? '' : 'field:'.$suggestedField);
        }

        $this->fieldOptions = ['ignore:' => 'Ignore this column'];

        foreach ($definition->fields() as $field) {
            $this->fieldOptions['field:'.$field->name] = $field->label.($field->required ? ' (required)' : '');
        }

        $this->refreshImporterOptions($definition);
        $this->refreshPresetOptions($definition->key());
        $this->refreshProcessingSummary();

        if ($this->selectedPresetId !== '') {
            $this->refreshPresetCompatibility();
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

        $value = $this->headerRowValue === 'custom' ? $this->customHeaderRowValue : $this->headerRowValue;

        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Choose a valid header row or select no header row.');
        }

        $headerRow = (int) $value;

        if ($headerRow > $this->sheetRowCount && $this->sheetRowCount > 0) {
            throw new InvalidArgumentException('The selected header row is outside the worksheet.');
        }

        return $headerRow;
    }

    /** @return array<int, string|null> */
    private function selectedMapping(): array
    {
        $mapping = [];

        foreach (array_keys($this->headerCells) as $sourceColumn) {
            $choice = $this->mapping[$sourceColumn] ?? '';

            if ($choice === 'ignore:') {
                $mapping[$sourceColumn] = null;
            } elseif (is_string($choice) && str_starts_with($choice, 'field:') && strlen($choice) > 6) {
                $mapping[$sourceColumn] = substr($choice, 6);
            } else {
                throw new InvalidArgumentException('Choose an importer field or explicitly ignore every source column before saving this preset.');
            }
        }

        return $mapping;
    }

    private function refreshImporterOptions(\SmartImport\Contracts\ImportDefinition $definition): void
    {
        $previous = $this->importerOptions;
        $this->importerOptionDefinitions = [];
        $this->importerOptions = [];

        if (! $definition instanceof ImportOptionProvider) {
            return;
        }

        foreach ($definition->importOptions() as $option) {
            $this->importerOptionDefinitions[$option->key] = [
                'label' => $option->label,
                'choices' => $option->choices,
                'default' => $option->default,
            ];
            $this->importerOptions[$option->key] = is_string($previous[$option->key] ?? null)
                && array_key_exists($previous[$option->key], $option->choices)
                ? $previous[$option->key]
                : $option->default;
        }
    }

    private function resolvedImporterOptions(): array
    {
        [$session, $definition] = $this->authorizedContext();

        return app(ImportOptionResolver::class)->resolve($definition, $this->importerOptions);
    }

    private function refreshProcessingSummary(): void
    {
        if ($this->sessionId === '') {
            return;
        }

        $session = app(ImportCoordinator::class)->get($this->sessionId, $this->actor());

        if ($session->importer_key === null) {
            return;
        }

        $definition = app(ImporterRegistry::class)->get($session->importer_key);
        $policy = $definition->writePolicy();
        $keys = $definition->uniqueKeyFields();
        $blankPolicies = [];
        $selectedFields = [];

        foreach ($this->mapping as $choice) {
            if (is_string($choice) && str_starts_with($choice, 'field:')) {
                $selectedFields[substr($choice, 6)] = true;
            }
        }

        foreach ($definition->fields() as $field) {
            if (isset($selectedFields[$field->name])) {
                $blankPolicies[] = $field->label.': '.$field->blankCellPolicy->value;
            }
        }

        $summary = [
            'Write mode: '.$policy->mode->value,
            'Match key fields: '.($keys === [] ? 'none' : implode(' + ', $keys)),
            'Blank handling: '.($blankPolicies === [] ? 'select columns to see field behavior' : implode('; ', $blankPolicies)),
            'Invalid rows: '.($policy->allowInvalidRowExclusion ? 'may be excluded at confirmation' : 'must be corrected before confirmation'),
        ];

        if ($policy->insertConflict !== null) {
            $summary[] = 'Existing row on insert: '.$policy->insertConflict->value;
        }

        if ($policy->missingUpdateTarget !== null) {
            $summary[] = 'Missing row on update: '.$policy->missingUpdateTarget->value;
        }

        $this->processingSummary = implode('. ', $summary).'.';
    }

    private function refreshPresetOptions(string $importerKey): void
    {
        $this->presetSelectOptions = ['' => 'Choose a saved preset'];

        foreach (app(MappingPresetService::class)->forImporter($importerKey, $this->actor()) as $preset) {
            $this->presetSelectOptions[(string) $preset->getKey()] = $preset->name;
        }
    }

    private function refreshPresetCompatibility(): void
    {
        if ($this->selectedPresetId === '') {
            $this->presetCompatibility = [];
            $this->presetMessage = '';

            return;
        }

        try {
            $this->presetCompatibility = $this->resolveSelectedPreset();
            $this->presetMessage = $this->presetCompatibility['compatible']
                ? 'This preset matches the selected importer, sheet, header, and reader settings. Apply it to replace the current mapping.'
                : $this->presetIncompatibilityMessage((string) $this->presetCompatibility['incompatibility']);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (InvalidArgumentException | DomainException $exception) {
            $this->presetCompatibility = ['compatible' => false, 'incompatibility' => 'unavailable'];
            $this->presetMessage = 'This preset is not available for the current importer and source.';
        }
    }

    private function resolveSelectedPreset(): array
    {
        [$session, $definition] = $this->authorizedContext();

        return app(MappingPresetService::class)->resolve(
            $this->selectedPresetId,
            $definition->key(),
            $this->sheetIndex,
            $this->sheetNames[$this->sheetIndex] ?? 'Sheet',
            $this->selectedHeaderRow(),
            $this->headerCells,
            $this->actor(),
            ['reader' => $this->selectedReaderOptions()],
        );
    }

    private function presetIncompatibilityMessage(string $reason): string
    {
        return match ($reason) {
            'importer_version' => 'This preset was saved for a different importer version.',
            'reader_options' => 'Choose the same CSV delimiter and encoding used when this preset was saved.',
            'source_fingerprint' => 'This preset belongs to a different worksheet or header. Select the matching sheet and header row.',
            'mapping_invalid' => 'This preset contains a mapping that is no longer allowed by the importer.',
            'importer_options' => 'This preset contains importer options that are no longer supported.',
            'options_invalid' => 'This preset contains settings that cannot be used safely.',
            default => 'This preset is not compatible with the current source and importer.',
        };
    }

    /** @return array{csv_delimiter: ?string, csv_encoding: string} */
    private function selectedReaderOptions(): array
    {
        $delimiter = match ($this->csvDelimiterValue) {
            'auto' => null,
            'tab' => "\t",
            default => $this->csvDelimiterValue,
        };

        return app(SpreadsheetReader::class)->normalizeCsvOptions($delimiter, $this->csvEncodingValue);
    }
}
