<?php

namespace SmartImport\Filament\Pages;

use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use SmartImport\Enums\ImportStatus;
use SmartImport\Models\ImportSession;
use SmartImport\Services\ImportAuthorization;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImportPreviewService;
use SmartImport\Services\ImporterRegistry;
use SmartImport\Services\StagedRowService;

final class ImportReviewPage extends ImportHubPage
{
    protected static ?string $slug = 'imports/{session}/review';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Review import';

    protected string $view = 'smart-import::filament.pages.import-review';

    #[Locked]
    public string $sessionId;

    #[Locked]
    public string $filename = '';

    #[Locked]
    public string $importerLabel = '';

    #[Locked]
    public int $stagingRevision = 0;

    #[Locked]
    public array $review = [];

    #[Locked]
    public bool $canCorrectRows = false;

    public int $rowPage = 1;

    public string $validationFilter = '';

    public string $changeFilter = '';

    public bool $duplicatesOnly = false;

    public ?int $editingRowId = null;

    public string $editingField = '';

    public string $editingValue = '';

    public function mount(string $session): void
    {
        $this->sessionId = $session;
        $this->refreshReview();
    }

    public function content(Schema $schema): Schema
    {
        return $schema;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generatePreview')
                ->label('Generate change preview')
                ->icon(Heroicon::OutlinedEye)
                ->visible(fn (): bool => $this->canGeneratePreview())
                ->action(fn () => $this->generatePreview()),
            Action::make('confirmImport')
                ->label('Confirm and queue')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Confirm this import?')
                ->modalDescription(fn (): string => $this->confirmationDescription())
                ->modalSubmitActionLabel('Queue import')
                ->schema(fn (): array => $this->invalidExclusionCapability()
                    ? [
                        Checkbox::make('exclude_invalid_rows')
                            ->label('Exclude invalid rows from this import')
                            ->helperText('Invalid rows will remain in the review report and will not be processed.'),
                    ]
                    : [])
                ->visible(fn (): bool => $this->canConfirm())
                ->action(function (array $data): void {
                    $session = $this->authorizedSession();

                    try {
                        $queued = app(ImportPreviewService::class)->confirm(
                            $this->sessionId,
                            $this->actor(),
                            $this->stagingRevision,
                            (bool) ($data['exclude_invalid_rows'] ?? false),
                        );
                    } catch (AuthorizationException) {
                        Notification::make()->danger()->title('Confirmation is not authorized')->send();

                        return;
                    } catch (DomainException|InvalidArgumentException $exception) {
                        Notification::make()->danger()->title('Import could not be queued')->body($exception->getMessage())->send();
                        $this->refreshReview();

                        return;
                    }

                    Notification::make()->success()->title('Import queued')->body('Progress is saved and can be reopened at any time.')->send();
                    $this->redirect(ImportProgressPage::getUrl(['session' => $queued->getKey()]), navigate: true);
                }),
            Action::make('backToInbox')
                ->label('Back to inbox')
                ->url(ImportInboxPage::getUrl()),
        ];
    }

    public function refreshReview(): void
    {
        $session = $this->authorizedSession();
        $definition = app(ImporterRegistry::class)->get((string) $session->importer_key);
        app(ImportAuthorization::class)->authorize('useImporter', $this->actor(), $definition);

        $this->filename = $session->original_filename;
        $this->importerLabel = $definition->label();
        $this->stagingRevision = (int) $session->staging_revision;
        $this->canCorrectRows = $this->canEdit();
        $this->review = app(ImportPreviewService::class)->rows(
            $this->sessionId,
            $this->actor(),
            $this->rowPage,
            25,
            $this->validationFilter === '' ? null : $this->validationFilter,
            $this->changeFilter === '' ? null : $this->changeFilter,
            $this->duplicatesOnly,
            allowMissingPreview: true,
        );
    }

    public function updatedValidationFilter(): void
    {
        $this->rowPage = 1;
        $this->refreshReview();
    }

    public function updatedChangeFilter(): void
    {
        $this->rowPage = 1;
        $this->refreshReview();
    }

    public function updatedDuplicatesOnly(): void
    {
        $this->rowPage = 1;
        $this->refreshReview();
    }

    public function updatedEditingField(): void
    {
        if ($this->editingRowId === null || ! array_key_exists($this->editingField, $this->editableFieldOptions($this->editingRowId))) {
            $this->editingValue = '';

            return;
        }

        $row = collect($this->review['rows'] ?? [])->firstWhere('id', $this->editingRowId);
        $value = is_array($row) ? ($row['mapped_values'][$this->editingField] ?? null) : null;
        $this->editingValue = is_scalar($value) && $value !== '[redacted]' ? (string) $value : '';
    }

    public function setRowPage(int $page): void
    {
        $this->rowPage = max(1, min($page, (int) ($this->review['last_page'] ?? 1)));
        $this->refreshReview();
    }

    public function beginEdit(int $rowId): void
    {
        $this->authorizedSession();

        if (! $this->canEdit()) {
            throw new AuthorizationException();
        }

        $row = collect($this->review['rows'] ?? [])->firstWhere('id', $rowId);

        if (! is_array($row) || $row['execution_status'] !== 'pending') {
            throw new DomainException('Only a pending staged row can be corrected.');
        }

        $this->editingRowId = $rowId;
        $this->editingField = '';
        $this->editingValue = '';
    }

    public function cancelEdit(): void
    {
        $this->editingRowId = null;
        $this->editingField = '';
        $this->editingValue = '';
    }

    public function saveEdit(int $rowId): void
    {
        if ($this->editingRowId !== $rowId || $this->editingField === '') {
            throw new InvalidArgumentException('Choose a mapped field before saving a correction.');
        }

        try {
            app(StagedRowService::class)->edit(
                $this->sessionId,
                $rowId,
                $this->editingField,
                $this->editingValue,
                $this->actor(),
                $this->stagingRevision,
            );
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('Correction is not authorized')->send();
            $this->refreshReview();

            return;
        } catch (DomainException|InvalidArgumentException $exception) {
            Notification::make()->danger()->title('Correction could not be saved')->body($exception->getMessage())->send();
            $this->refreshReview();

            return;
        }

        $this->cancelEdit();
        Notification::make()->success()->title('Correction saved')->body('Validation and duplicate checks were refreshed. Generate a new change preview before confirming.')->send();
        $this->refreshReview();
    }

    /** @return array<string, string> */
    public function editableFieldOptions(int $rowId): array
    {
        $session = $this->authorizedSession();
        $definition = app(ImporterRegistry::class)->get((string) $session->importer_key);
        $mappedFields = array_values(array_unique(array_filter($session->mapping ?? [], 'is_string')));
        $options = [];

        foreach ($definition->fields() as $field) {
            if (! in_array($field->name, $mappedFields, true)) {
                continue;
            }

            if ($field->sensitive
                && ! app(ImportAuthorization::class)->allows('editSensitiveImportFields', $this->actor(), [$session, $field])) {
                continue;
            }

            $options[$field->name] = $field->label;
        }

        return $options;
    }

    private function generatePreview(): void
    {
        try {
            $result = app(ImportPreviewService::class)->generateOrQueue(
                $this->sessionId,
                $this->actor(),
                $this->stagingRevision,
            );
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('Preview generation is not authorized')->send();

            return;
        } catch (DomainException|InvalidArgumentException $exception) {
            Notification::make()->danger()->title('Change preview could not be generated')->body($exception->getMessage())->send();
            $this->refreshReview();

            return;
        }

        if ($result['queued']) {
            Notification::make()
                ->success()
                ->title($result['already_queued'] ? 'Change preview is already running' : 'Change preview queued')
                ->body('Preview progress is saved and can be reopened after a refresh.')
                ->send();
        } else {
            Notification::make()->success()->title('Change preview ready')->body('Review the planned changes before confirming.')->send();
        }

        $this->refreshReview();
    }

    private function authorizedSession(): ImportSession
    {
        $session = app(ImportCoordinator::class)->get($this->sessionId, $this->actor());

        if ($session->status !== ImportStatus::ReviewReady
            || $session->importer_key === null
            || $session->importer_version === null) {
            abort(404);
        }

        return $session;
    }

    private function canGeneratePreview(): bool
    {
        if (($this->review['preview_state'] ?? null) === 'building') {
            return false;
        }

        $session = $this->authorizedSession();

        return app(ImportAuthorization::class)->allows('update', $this->actor(), $session);
    }

    private function canEdit(): bool
    {
        $session = $this->authorizedSession();

        return $session->status === ImportStatus::ReviewReady
            && app(ImportAuthorization::class)->allows('update', $this->actor(), $session);
    }

    private function canConfirm(): bool
    {
        if (($this->review['preview_state'] ?? null) !== 'ready'
            || ! is_array($this->review['preview_summary'] ?? null)) {
            return false;
        }

        $session = $this->authorizedSession();
        $definition = app(ImporterRegistry::class)->get((string) $session->importer_key);
        $authorization = app(ImportAuthorization::class);

        if (! $authorization->allows('queue', $this->actor(), $session)
            || ! $authorization->allows('useImporter', $this->actor(), $definition)) {
            return false;
        }

        return (int) $this->review['stage_summary']['invalid'] === 0
            || $definition->writePolicy()->allowInvalidRowExclusion;
    }

    private function invalidExclusionCapability(): bool
    {
        $session = $this->authorizedSession();
        $definition = app(ImporterRegistry::class)->get((string) $session->importer_key);

        return $definition->writePolicy()->allowInvalidRowExclusion
            && (int) ($this->review['stage_summary']['invalid'] ?? 0) > 0;
    }

    private function confirmationDescription(): string
    {
        $summary = $this->review['preview_summary'] ?? [];
        $invalid = (int) ($this->review['stage_summary']['invalid'] ?? 0);
        $planned = (int) ($summary['total'] ?? 0);

        return sprintf(
            'This queues %d staged row(s) under revision %d. %d row(s) are invalid and will only be excluded if you select that option. Rows already committed will remain in place if you cancel or a later row fails.',
            $planned,
            $this->stagingRevision,
            $invalid,
        );
    }
}
