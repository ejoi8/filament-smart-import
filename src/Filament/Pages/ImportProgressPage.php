<?php

namespace SmartImport\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use DomainException;
use Livewire\Attributes\Locked;
use SmartImport\Enums\ImportStatus;
use SmartImport\Services\ImportProgressService;
use SmartImport\Services\ImportRetryService;

final class ImportProgressPage extends ImportHubPage
{
    protected static ?string $slug = 'imports/{session}/progress';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Import progress';

    protected string $view = 'smart-import::filament.pages.import-progress';

    #[Locked]
    public string $sessionId;

    #[Locked]
    public array $progress = [];

    #[Locked]
    public bool $shouldPoll = false;

    public function mount(string $session): void
    {
        $this->sessionId = $session;
        $this->refreshProgress();
    }

    public function content(Schema $schema): Schema
    {
        return $schema;
    }

    public function refreshProgress(): void
    {
        $this->progress = app(ImportProgressService::class)->get($this->sessionId, $this->actor());
        $this->shouldPoll = in_array($this->progress['status'], [
            ImportStatus::Staging->value,
            ImportStatus::Queued->value,
            ImportStatus::Importing->value,
            ImportStatus::Cancelling->value,
        ], true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryFailedRows')
                ->label('Retry failed rows')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->progress['can_retry'] ?? false)
                ->action(function (): void {
                    try {
                        app(ImportRetryService::class)->retryFailedRows($this->sessionId, $this->actor());
                    } catch (AuthorizationException) {
                        Notification::make()->danger()->title('Retry is not authorized')->send();

                        return;
                    } catch (DomainException $exception) {
                        Notification::make()->warning()->title('Failed rows could not be queued')->body($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Failed rows queued for retry')->send();
                    $this->refreshProgress();
                }),
            Action::make('cancelImport')
                ->label('Cancel import')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => in_array($this->progress['status'] ?? null, [
                    ImportStatus::Queued->value,
                    ImportStatus::Importing->value,
                ], true) && ($this->progress['can_cancel'] ?? false))
                ->action(function (): void {
                    try {
                        $session = app(\SmartImport\Services\ImportExecutionLifecycle::class)
                            ->requestCancellation($this->sessionId, $this->actor());
                    } catch (AuthorizationException) {
                        Notification::make()->danger()->title('Cancellation is not authorized')->send();

                        return;
                    } catch (DomainException $exception) {
                        Notification::make()->warning()->title('Import could not be cancelled')->body($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title($session->status === ImportStatus::Cancelled ? 'Import cancelled' : 'Cancellation requested')
                        ->send();

                    $this->refreshProgress();
                }),
            Action::make('backToInbox')
                ->label('Back to inbox')
                ->url(ImportInboxPage::getUrl()),
            Action::make('reviewImport')
                ->label('Review import')
                ->url(ImportReviewPage::getUrl(['session' => $this->sessionId]))
                ->visible(fn (): bool => ($this->progress['status'] ?? null) === ImportStatus::ReviewReady->value),
        ];
    }
}
