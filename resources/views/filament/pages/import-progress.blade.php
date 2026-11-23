<x-filament-panels::page>
    @php($pollSeconds = max(1, min(60, (int) config('smart-import.progress.polling_interval_seconds', 2))))
    @if ($shouldPoll)
        <div wire:poll.{{ $pollSeconds }}s="refreshProgress" class="space-y-6">
    @else
        <div class="space-y-6">
    @endif
        @if (($progress['status'] ?? null) === 'failed')
            <x-filament::section heading="Import needs attention">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5" role="alert">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::badge color="danger">Failed</x-filament::badge>
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">The last attempt did not finish.</p>
                    </div>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        {{ $progress['error_summary'] ?? 'Check the application log and the queue worker before retrying.' }}
                    </p>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Check <code>storage/logs/laravel.log</code> and the configured queue worker. Retry failed rows only after the underlying issue is resolved.
                    </p>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Import status">
            @if ($progress['no_recent_heartbeat'] ?? false)
                <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5" role="status">
                    <p class="text-sm text-warning-700 dark:text-warning-300">
                        There has been no recent progress update. The queue may be delayed or a worker may need attention; this page cannot determine worker presence.
                    </p>
                </div>
            @endif
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">File</dt>
                    <dd class="mt-1 break-words text-sm font-medium text-gray-950 dark:text-white">{{ $progress['filename'] }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="mt-2">
                        <x-filament::badge :color="['staging' => 'info', 'review_ready' => 'warning', 'queued' => 'gray', 'importing' => 'info', 'cancelling' => 'warning', 'completed' => 'success', 'failed' => 'danger', 'cancelled' => 'gray'][$progress['status'] ?? ''] ?? 'gray'">
                            {{ str($progress['status'] ?? 'not staged')->headline() }}
                        </x-filament::badge>
                    </dd>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Source</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ str($progress['source'])->headline() }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Attempt</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $progress['attempt'] }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Validation staging">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">Row validation and duplicate detection</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Staging reads the file and validates rows without changing target records.</p>
                </div>
                <x-filament::badge :color="['staging' => 'info', 'completed' => 'success', 'failed' => 'danger', 'pending' => 'gray'][$progress['stage']['status'] ?? ''] ?? 'gray'">
                    {{ str($progress['stage']['status'] ?? 'pending')->headline() }}
                </x-filament::badge>
            </div>

            @if (($progress['stage']['status'] ?? null) === 'staging' && ($progress['stage']['processed'] ?? 0) === 0)
                <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5" role="status">
                    <p class="text-sm text-primary-700 dark:text-primary-300">
                        Validation staging is queued. If this stays at zero, check that a queue worker is running for the configured connection and the "{{ config('smart-import.queue.name', 'imports') }}" queue.
                    </p>
                </div>
            @elseif (($progress['stage']['status'] ?? null) === 'completed' && ($progress['status'] ?? null) === 'review_ready')
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        Staging is complete. Review the rows and generate the change preview before confirming.
                    </p>
                    <x-filament::button tag="a" href="{{ \SmartImport\Filament\Pages\ImportReviewPage::getUrl(['session' => $this->sessionId]) }}" size="sm" icon="heroicon-o-eye">
                        Review import
                    </x-filament::button>
                </div>
            @endif

            <div class="mt-5 space-y-4">
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium text-gray-950 dark:text-white">{{ $progress['stage']['percentage'] }}%</span>
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ $progress['stage']['processed'] }} of {{ $progress['stage']['total'] }} rows staged
                    </span>
                </div>
                <div
                    class="h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
                    role="progressbar"
                    aria-label="Validation staging progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="{{ $progress['stage']['percentage'] }}"
                >
                    <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $progress['stage']['percentage'] }}%"></div>
                </div>
            </div>

            <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Valid</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['stage']['valid'] }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Invalid</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['stage']['invalid'] }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Warnings</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['stage']['warnings'] }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Remaining</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['stage']['remaining'] }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Execution progress">
            @if ($progress['execution']['available'])
                <div class="space-y-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-950 dark:text-white">{{ $progress['execution']['percentage'] }}%</span>
                        <span class="text-gray-500 dark:text-gray-400" aria-live="polite">
                            {{ $progress['execution']['processed'] }} of {{ $progress['execution']['total'] }} rows processed
                        </span>
                    </div>
                    <div
                        class="h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
                        role="progressbar"
                        aria-label="Import execution progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="{{ $progress['execution']['percentage'] }}"
                    >
                        <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $progress['execution']['percentage'] }}%"></div>
                    </div>

                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Created</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['execution']['created'] }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Updated</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['execution']['updated'] }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Skipped</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['execution']['skipped'] }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Failed</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['execution']['failed'] }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Remaining</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['execution']['remaining'] }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Processed</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['execution']['processed'] }}</dd>
                        </div>
                    </dl>
                </div>
            @else
                <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 dark:border-white/20 dark:bg-white/5">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Execution has not started</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Staging and review must complete first. Confirmation freezes the reviewed revision and queues the target writes.
                    </p>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Activity">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Execution started</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $progress['timestamps']['started'] === null ? 'Not started' : \Illuminate\Support\Carbon::parse($progress['timestamps']['started'])->format('M j, Y H:i:s') }}
                    </dd>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Execution duration</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        @if ($progress['timestamps']['duration_seconds'] === null)
                            Not started
                        @else
                            {{ intdiv($progress['timestamps']['duration_seconds'], 3600) }}h
                            {{ intdiv($progress['timestamps']['duration_seconds'] % 3600, 60) }}m
                            {{ $progress['timestamps']['duration_seconds'] % 60 }}s
                        @endif
                    </dd>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Last activity</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $progress['timestamps']['last_activity'] === null ? '—' : \Illuminate\Support\Carbon::parse($progress['timestamps']['last_activity'])->format('M j, Y H:i:s') }}
                    </dd>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Finished</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        @if ($progress['timestamps']['finished'] !== null)
                            {{ \Illuminate\Support\Carbon::parse($progress['timestamps']['finished'])->format('M j, Y H:i:s') }}
                        @elseif (in_array($progress['status'] ?? null, ['staging', 'queued', 'importing', 'cancelling'], true))
                            In progress
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Recent row errors">
            @if ($progress['recent_errors'] === [])
                <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-8 text-center dark:border-white/20 dark:bg-white/5">
                    <x-filament::icon icon="heroicon-o-check-circle" class="mx-auto h-8 w-8 text-gray-400" />
                    <p class="mt-3 text-sm font-medium text-gray-950 dark:text-white">No execution errors recorded</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Staging or validation problems appear on the Review page instead.</p>
                </div>
            @else
                <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">Latest {{ count($progress['recent_errors']) }} execution error(s).</p>
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($progress['recent_errors'] as $error)
                        <li class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-950 dark:text-white">Source row {{ $error['row'] }}</p>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $error['message'] }}</p>
                            </div>
                            <x-filament::badge color="danger">{{ str($error['code'])->headline() }}</x-filament::badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
