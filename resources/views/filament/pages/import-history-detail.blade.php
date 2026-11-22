<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Import summary">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">File</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $history['filename'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ str($history['status'])->headline() }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Source</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ str($history['source'])->headline() }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">File type and size</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ strtoupper($history['file_type']) }} · {{ number_format($history['file_size'] / 1024, 1) }} KB
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Initiated by</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $history['created_by_type'] === null ? 'Unknown' : class_basename($history['created_by_type']).' #'.$history['created_by_id'] }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Tenant</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $history['tenant_id'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Received</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $history['received_at'] === null ? '—' : \Illuminate\Support\Carbon::parse($history['received_at'])->format('M j, Y H:i:s') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Processing duration</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        @if ($history['duration_seconds'] === null)
                            Not started
                        @else
                            {{ intdiv($history['duration_seconds'], 3600) }}h
                            {{ intdiv($history['duration_seconds'] % 3600, 60) }}m
                            {{ $history['duration_seconds'] % 60 }}s
                        @endif
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Importer and sheet">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Importer</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $history['importer_key'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Importer version</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $history['importer_version'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Target model</dt>
                    <dd class="mt-1 break-all text-sm text-gray-950 dark:text-white">{{ $history['target_model'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Worksheet</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $history['sheet_name'] ?? '—' }}
                        @if ($history['sheet_index'] !== null)
                            ({{ $history['sheet_index'] }})
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Header row</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $history['sheet_header_row'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Mapping revision</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $history['mapping_revision'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Staging revision</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $history['staging_revision'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Confirmed revision</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $history['confirmed_revision'] ?? '—' }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Validation results">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ([
                    'Rows in source' => 'total',
                    'Rows checked' => 'processed',
                    'Valid' => 'valid',
                    'Invalid' => 'invalid',
                    'Warnings' => 'warnings',
                ] as $label => $key)
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $history['stage'][$key] }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section heading="Committed results">
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
                Attempt {{ $history['attempt'] }} · {{ $history['execution']['processed'] }} of {{ $history['execution']['total'] }} rows processed
            </p>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                @foreach ([
                    'Created' => 'created',
                    'Updated' => 'updated',
                    'Skipped' => 'skipped',
                    'Failed' => 'failed',
                    'Unchanged' => 'unchanged',
                    'Excluded' => 'excluded',
                    'Snapshot conflicts' => 'snapshot_conflicts',
                ] as $label => $key)
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $history['execution'][$key] }}</dd>
                    </div>
                @endforeach
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Started</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $history['started_at'] === null ? '—' : \Illuminate\Support\Carbon::parse($history['started_at'])->format('M j, Y H:i:s') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Finished</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $history['finished_at'] === null ? '—' : \Illuminate\Support\Carbon::parse($history['finished_at'])->format('M j, Y H:i:s') }}
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Notification delivery">
            <p class="text-sm text-gray-700 dark:text-gray-200">
                Latest terminal notification: {{ str($history['notification_delivery_status'])->headline() }}
            </p>
        </x-filament::section>

        <x-filament::section heading="Row results">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Open individual rows to review validation outcomes, target references, and field changes.
            </p>
            <a
                href="{{ \SmartImport\Filament\Pages\ImportHistoryRowsPage::getUrl(['session' => $history['id']]) }}"
                class="mt-3 inline-flex text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
            >
                View row results
            </a>
        </x-filament::section>

        @if ($history['attempts'] !== [])
            <x-filament::section heading="Attempt history">
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($history['attempts'] as $attempt)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <span class="text-sm text-gray-950 dark:text-white">
                                Attempt {{ $attempt['attempt'] ?? '—' }}
                                @if (($attempt['retry'] ?? null) === 'failed_rows_only')
                                    · failed rows only
                                @endif
                            </span>
                            <span class="text-sm text-gray-600 dark:text-gray-300">
                                {{ str($attempt['status'] ?? 'unknown')->headline() }}
                                · {{ $attempt['processed'] ?? 0 }} processed
                                · {{ $attempt['created_count'] ?? 0 }} created
                                · {{ $attempt['updated_count'] ?? 0 }} updated
                                · {{ $attempt['skipped_count'] ?? 0 }} skipped
                                · {{ $attempt['failed_count'] ?? 0 }} failed
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($history['audit'] !== [])
            <x-filament::section heading="Audit history">
                <ol class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($history['audit'] as $entry)
                        <li class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div>
                                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ str($entry['event'])->replace('.', ' · ')->headline() }}</p>
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                    {{ $entry['reason_code'] ?? '—' }}
                                    · {{ $entry['actor_type'] === null ? 'System' : class_basename($entry['actor_type']).' #'.$entry['actor_id'] }}
                                </p>
                            </div>
                            <time class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $entry['occurred_at'] === null ? '—' : \Illuminate\Support\Carbon::parse($entry['occurred_at'])->format('M j, Y H:i:s') }}
                            </time>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
