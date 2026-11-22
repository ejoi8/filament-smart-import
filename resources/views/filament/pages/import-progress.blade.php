<x-filament-panels::page>
    @php($pollSeconds = max(1, min(60, (int) config('smart-import.progress.polling_interval_seconds', 2))))
    @if ($shouldPoll)
        <div wire:poll.{{ $pollSeconds }}s="refreshProgress" class="space-y-6">
    @else
        <div class="space-y-6">
    @endif
        <x-filament::section heading="Import status">
            @if ($progress['no_recent_heartbeat'] ?? false)
                <p class="mb-4 text-sm text-warning-700 dark:text-warning-300" role="status">
                    There has been no recent progress update. The queue may be delayed or a worker may need attention; this page cannot determine worker presence.
                </p>
            @endif
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">File</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $progress['filename'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ str($progress['status'] ?? 'not staged')->headline() }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Source</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ str($progress['source'])->headline() }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Attempt</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $progress['attempt'] }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Execution progress">
            <div class="space-y-4">
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium text-gray-950 dark:text-white">{{ $progress['execution']['percentage'] }}%</span>
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ $progress['execution']['processed'] }} of {{ $progress['execution']['total'] }} rows processed
                    </span>
                </div>
                <div
                    class="h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
                    role="progressbar"
                    aria-label="Import progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="{{ $progress['execution']['percentage'] }}"
                >
                    <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $progress['execution']['percentage'] }}%"></div>
                </div>

                <dl class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ([
                        'Created' => 'created',
                        'Updated' => 'updated',
                        'Skipped' => 'skipped',
                        'Failed' => 'failed',
                        'Remaining' => 'remaining',
                    ] as $label => $key)
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['execution'][$key] }}</dd>
                        </div>
                    @endforeach
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Processed</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $progress['execution']['processed'] }}</dd>
                    </div>
                </dl>
            </div>
        </x-filament::section>

        <x-filament::section heading="Validation staging">
            @if (($progress['status'] ?? null) === \SmartImport\Enums\ImportStatus::Staging->value && ($progress['stage']['processed'] ?? 0) === 0)
                <p class="mb-4 text-sm text-primary-700 dark:text-primary-300" role="status">
                    Validation staging is queued. If this stays at zero, check that a queue worker is running for the configured connection and the "{{ config('smart-import.queue.name', 'imports') }}" queue.
                </p>
            @endif
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ str($progress['stage']['status'] ?? 'not staged')->headline() }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Processed</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $progress['stage']['processed'] }} of {{ $progress['stage']['total'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Valid</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $progress['stage']['valid'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Invalid</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $progress['stage']['invalid'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Warnings</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $progress['stage']['warnings'] }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Activity">
            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Processing duration</dt>
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
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Last activity</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $progress['timestamps']['last_activity'] === null ? '—' : \Illuminate\Support\Carbon::parse($progress['timestamps']['last_activity'])->format('M j, Y H:i:s') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Finished</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $progress['timestamps']['finished'] === null ? 'In progress' : \Illuminate\Support\Carbon::parse($progress['timestamps']['finished'])->format('M j, Y H:i:s') }}
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Recent row errors">
            @if ($progress['recent_errors'] === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">No execution errors have been recorded.</p>
            @else
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($progress['recent_errors'] as $error)
                        <li class="flex flex-wrap items-start justify-between gap-2 py-3 first:pt-0 last:pb-0">
                            <span class="text-sm text-gray-950 dark:text-white">Source row {{ $error['row'] }}: {{ $error['message'] }}</span>
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ str($error['code'])->headline() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
