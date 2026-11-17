<?php

namespace SmartImport\Filament\Pages;

use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Locked;
use SmartImport\Enums\ImportStatus;
use SmartImport\Services\ImportAuthorization;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImportExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImportHistoryDetailPage extends ImportHubPage
{
    protected static ?string $slug = 'imports/{session}/history';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Import history details';

    protected string $view = 'smart-import::filament.pages.import-history-detail';

    #[Locked]
    public array $history = [];

    public function mount(string $session): void
    {
        $record = app(ImportCoordinator::class)->get($session, $this->actor());

        abort_unless(in_array($record->status, [ImportStatus::Completed, ImportStatus::Failed, ImportStatus::Cancelled], true), 404);

        $startedAt = $record->started_at;
        $finishedAt = $record->finished_at;
        $attempts = collect($record->attempt_history ?? [])
            ->map(static function (array $attempt): array {
                $allowed = [
                    'attempt',
                    'confirmed_revision',
                    'execution_total',
                    'status',
                    'processed',
                    'created_count',
                    'updated_count',
                    'skipped_count',
                    'failed_count',
                    'finished_at',
                    'retry',
                    'retry_of_attempt',
                    'queued_at',
                ];

                return array_intersect_key($attempt, array_flip($allowed));
            })
            ->values()
            ->all();

        $this->history = [
            'id' => (string) $record->getKey(),
            'filename' => $record->original_filename,
            'source' => $record->source,
            'file_type' => $record->file_type,
            'file_size' => (int) $record->file_size,
            'status' => $record->status?->value,
            'importer_key' => $record->importer_key,
            'importer_version' => $record->importer_version,
            'target_model' => $record->target_model,
            'created_by_type' => $record->created_by_type,
            'created_by_id' => $record->created_by_id,
            'tenant_id' => $record->tenant_id,
            'sheet_name' => $record->sheet_name,
            'sheet_index' => $record->sheet_index,
            'sheet_header_row' => $record->sheet_header_row,
            'mapping_revision' => (int) $record->mapping_revision,
            'staging_revision' => (int) $record->staging_revision,
            'confirmed_revision' => $record->confirmed_revision === null ? null : (int) $record->confirmed_revision,
            'stage' => [
                'total' => (int) $record->stage_total,
                'processed' => (int) $record->stage_processed,
                'valid' => (int) $record->stage_valid,
                'invalid' => (int) $record->stage_invalid,
                'warnings' => (int) $record->stage_warning,
            ],
            'execution' => [
                'total' => (int) $record->execution_total,
                'processed' => (int) $record->processed,
                'created' => (int) $record->created_count,
                'updated' => (int) $record->updated_count,
                'skipped' => (int) $record->skipped_count,
                'failed' => (int) $record->failed_count,
            ],
            'attempt' => (int) $record->current_attempt,
            'attempts' => $attempts,
            'received_at' => $record->created_at?->toIso8601String(),
            'started_at' => $startedAt?->toIso8601String(),
            'finished_at' => $finishedAt?->toIso8601String(),
            'duration_seconds' => $startedAt === null
                ? null
                : max(0, ($finishedAt ?? now())->getTimestamp() - $startedAt->getTimestamp()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewProgress')
                ->label('View progress')
                ->icon(Heroicon::OutlinedChartBar)
                ->url(ImportProgressPage::getUrl(['session' => $this->history['id']])),
            Action::make('viewRows')
                ->label('View row results')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(ImportHistoryRowsPage::getUrl(['session' => $this->history['id']])),
            Action::make('downloadSummary')
                ->label('Download summary CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $this->canExport())
                ->action(fn (): StreamedResponse => $this->downloadReport('summary')),
            Action::make('downloadFailures')
                ->label('Download failed rows CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $this->canExport())
                ->action(fn (): StreamedResponse => $this->downloadReport('failures')),
            Action::make('backToHistory')
                ->label('Back to history')
                ->icon(Heroicon::OutlinedClock)
                ->url(ImportHistoryPage::getUrl()),
            Action::make('backToInbox')
                ->label('Back to inbox')
                ->icon(Heroicon::OutlinedInboxStack)
                ->url(ImportInboxPage::getUrl()),
        ];
    }

    private function canExport(): bool
    {
        $actor = $this->actor();

        try {
            $session = app(ImportCoordinator::class)->get($this->history['id'], $actor);
        } catch (AuthorizationException|ModelNotFoundException) {
            return false;
        }

        return app(ImportAuthorization::class)->allows('export', $actor, $session);
    }

    private function downloadReport(string $report): StreamedResponse
    {
        $sessionId = $this->history['id'];
        $actor = $this->actor();
        $exports = app(ImportExportService::class);
        $lines = match ($report) {
            'summary' => $exports->summaryCsv($sessionId, $actor),
            'failures' => $exports->validationErrorsCsv($sessionId, $actor),
            default => throw new \InvalidArgumentException('Unknown import report.'),
        };

        return response()->streamDownload(
            static function () use ($lines): void {
                foreach ($lines as $line) {
                    echo $line;
                }
            },
            sprintf('smart-import-%s-%s.csv', $sessionId, $report),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
