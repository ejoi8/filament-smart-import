<x-filament-panels::page>
    @php($pollSeconds = max(1, min(60, (int) config('smart-import.progress.polling_interval_seconds', 2))))

    @if (($review['preview_state'] ?? null) === 'building')
        <div wire:poll.{{ $pollSeconds }}s="refreshReview" class="space-y-6">
    @else
        <div class="space-y-6">
    @endif
        <x-filament::section heading="File and importer">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">File</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $filename }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Importer</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $importerLabel }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Reviewed revision</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $stagingRevision }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Staging results</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $review['stage_summary']['valid'] }} valid,
                        {{ $review['stage_summary']['invalid'] }} invalid,
                        {{ $review['stage_summary']['warnings'] }} with warnings,
                        {{ $review['stage_summary']['excluded'] }} excluded
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        @if (($review['preview_state'] ?? null) === 'building')
            @php($previewTotal = max(0, (int) ($review['preview_total'] ?? 0)))
            @php($previewProcessed = min($previewTotal, max(0, (int) ($review['preview_processed'] ?? 0))))
            @php($previewPercentage = $previewTotal === 0 ? 0 : (int) floor(($previewProcessed / $previewTotal) * 100))
            <x-filament::section heading="Change preview is being generated">
                <div class="space-y-3" role="status" aria-live="polite">
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ $previewProcessed }} of {{ $previewTotal }} rows checked. Confirmation is disabled until the complete preview is saved.
                    </p>
                    <div class="h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-label="Change preview progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $previewPercentage }}">
                        <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $previewPercentage }}%"></div>
                    </div>
                    @if ($review['preview_no_recent_heartbeat'] ?? false)
                        <p class="text-sm text-warning-700 dark:text-warning-300">
                            There has been no recent progress update. The queue may be delayed or a worker may need attention; this page cannot determine worker presence.
                        </p>
                    @endif
                </div>
            </x-filament::section>
        @elseif (($review['preview_state'] ?? null) === 'failed')
            <x-filament::section heading="Change preview needs attention">
                <p class="text-sm text-danger-700 dark:text-danger-300" role="alert">{{ $review['preview_error'] ?? 'Preview generation failed. Retry preview generation.' }}</p>
            </x-filament::section>
        @elseif (($review['preview_state'] ?? null) === 'ready')
            @php($summary = $review['preview_summary'] ?? [])
            <x-filament::section heading="Planned changes">
                <dl class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach (['Rows checked' => 'total', 'New' => 'create', 'Updates' => 'update', 'Unchanged' => 'skip', 'Conflicts or failures' => 'fail'] as $label => $key)
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ (int) ($summary[$key] ?? 0) }}</dd>
                        </div>
                    @endforeach
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Excluded</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $review['stage_summary']['excluded'] }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                    Cancelling stops later rows. Rows already committed stay saved; failed rows can be reviewed and retried after the attempt finishes.
                </p>
            </x-filament::section>
        @else
            <x-filament::section heading="Before importing">
                <p class="text-sm text-gray-600 dark:text-gray-300">Generate a change preview to see planned creates, updates, unchanged rows, validation errors, and conflicts. Target records are not changed before confirmation.</p>
            </x-filament::section>
        @endif

        <x-filament::section heading="Staged rows">
            <div class="mb-5 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="block space-y-1.5">
                        <span class="text-sm font-medium text-gray-950 dark:text-white">Validation status</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="validationFilter" aria-label="Filter by validation status">
                                <option value="">All rows</option>
                                <option value="valid">Valid</option>
                                <option value="invalid">Invalid</option>
                                <option value="warning">Warnings</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    <label class="block space-y-1.5">
                        <span class="text-sm font-medium text-gray-950 dark:text-white">Match result</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="changeFilter" aria-label="Filter by match result">
                                <option value="">All match results</option>
                                <option value="new">New record</option>
                                <option value="matched">Matched record</option>
                                <option value="ambiguous">Ambiguous match</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    <div class="flex items-end">
                        <label class="flex items-center gap-2 pb-1 text-sm font-medium text-gray-950 dark:text-white">
                            <input type="checkbox" wire:model.live="duplicatesOnly" aria-label="Show duplicate rows only" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500">
                            Duplicate rows only
                        </label>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-500 dark:text-gray-400" aria-live="polite">
                        Showing {{ count($review['rows']) }} of {{ $review['total'] }} filtered rows. Sensitive values are hidden unless your access allows them.
                    </p>
                    @if ($validationFilter !== '' || $changeFilter !== '' || $duplicatesOnly)
                        <x-filament::button color="gray" size="sm" wire:click="clearRowFilters">
                            Clear filters
                        </x-filament::button>
                    @endif
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($review['rows'] as $row)
                    <article class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5" aria-labelledby="row-heading-{{ $row['id'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                            <div class="min-w-0">
                                <h3 id="row-heading-{{ $row['id'] }}" class="font-semibold text-gray-950 dark:text-white">
                                    Source row {{ $row['source_row_number'] }}
                                </h3>
                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span>Validation</span>
                                        <x-filament::badge :color="['valid' => 'success', 'warning' => 'warning'][$row['validation_status']] ?? 'danger'">{{ str($row['validation_status'])->headline() }}</x-filament::badge>
                                    </span>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span>Match</span>
                                        <x-filament::badge :color="['new' => 'info', 'matched' => 'gray', 'ambiguous' => 'warning'][$row['change_type'] ?? ''] ?? 'gray'">{{ str($row['change_type'] ?? 'not checked')->headline() }}</x-filament::badge>
                                    </span>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span>Execution</span>
                                        <x-filament::badge :color="['created' => 'success', 'updated' => 'info', 'failed' => 'danger', 'excluded' => 'warning'][$row['execution_status']] ?? 'gray'">{{ str($row['execution_status'])->headline() }}</x-filament::badge>
                                    </span>
                                </div>
                            </div>
                            @if ($canCorrectRows && $row['execution_status'] === 'pending')
                                <x-filament::button size="sm" color="gray" icon="heroicon-m-pencil-square" wire:click="beginEdit({{ $row['id'] }})" aria-label="Correct source row {{ $row['source_row_number'] }}">
                                    Correct row
                                </x-filament::button>
                            @endif
                        </div>

                        <div class="space-y-4 p-4">
                            @if ($row['mapped_values'] !== [])
                                <dl class="grid gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 sm:grid-cols-2 xl:grid-cols-3 dark:border-white/10 dark:bg-white/5">
                                    @foreach ($row['mapped_values'] as $field => $value)
                                        <div class="min-w-0">
                                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                                {{ $review['field_labels'][$field] ?? str($field)->headline() }}
                                            </dt>
                                            <dd class="mt-1 break-words text-sm text-gray-950 dark:text-white">
                                                @if ($value === '[redacted]')
                                                    <span class="italic text-gray-500 dark:text-gray-400">{{ $value }}</span>
                                                @else
                                                    {{ is_scalar($value) ? $value : json_encode($value) }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif

                            @if ($row['validation_errors'] !== [])
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5" role="alert">
                                    <div class="flex items-center gap-2">
                                        <x-filament::badge color="danger">Validation</x-filament::badge>
                                        <p class="text-sm font-semibold text-danger-700 dark:text-danger-300">Issues found</p>
                                    </div>
                                    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-danger-700 dark:text-danger-300" aria-label="Validation errors for source row {{ $row['source_row_number'] }}">
                                        @foreach ($row['validation_errors'] as $error)
                                            <li>{{ $error['field'] === null ? '' : (($review['field_labels'][$error['field']] ?? str($error['field'])->headline()).': ') }}{{ $error['message'] }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if (($review['preview_state'] ?? null) === 'ready' && is_array($row['preview']))
                                <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-semibold text-gray-950 dark:text-white">Planned action</p>
                                            @if (is_string($row['preview']['reason'] ?? null))
                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Reason: {{ str($row['preview']['reason'])->headline() }}</p>
                                            @endif
                                        </div>
                                        <x-filament::badge :color="['create' => 'success', 'update' => 'info', 'fail' => 'danger'][$row['preview']['action'] ?? ''] ?? 'gray'">{{ str($row['preview']['action'] ?? 'unknown')->headline() }}</x-filament::badge>
                                    </div>
                                    @if (($row['preview']['target_id'] ?? null) !== null)
                                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                            Target record: {{ class_basename((string) ($row['preview']['target_type'] ?? 'record')) }} #{{ $row['preview']['target_id'] }}
                                        </p>
                                    @endif

                                    @if (($row['preview']['changes'] ?? []) !== [])
                                        <div class="mt-3 overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                                            <div class="overflow-x-auto">
                                                <table class="w-full divide-y divide-gray-200 text-left text-sm dark:divide-white/10">
                                                    <caption class="sr-only">Planned field changes for source row {{ $row['source_row_number'] }}</caption>
                                                    <thead class="bg-gray-50 dark:bg-white/5">
                                                        <tr>
                                                            <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Field</th>
                                                            <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Current value</th>
                                                            <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Imported value</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                                        @foreach ($row['preview']['changes'] as $change)
                                                            <tr>
                                                                <th scope="row" class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $change['label'] ?? $change['field'] ?? 'Field' }}</th>
                                                                <td class="max-w-sm break-words px-3 py-2">
                                                                    @if (! ($change['before_exists'] ?? false))
                                                                        —
                                                                    @elseif (($change['before'] ?? null) === '[redacted]')
                                                                        <span class="italic text-gray-500 dark:text-gray-400">{{ $change['before'] }}</span>
                                                                    @else
                                                                        {{ is_scalar($change['before'] ?? null) ? $change['before'] : json_encode($change['before'] ?? null) }}
                                                                    @endif
                                                                </td>
                                                                <td class="max-w-sm break-words px-3 py-2">
                                                                    @if (($change['after'] ?? null) === '[redacted]')
                                                                        <span class="italic text-gray-500 dark:text-gray-400">{{ $change['after'] }}</span>
                                                                    @else
                                                                        {{ is_scalar($change['after'] ?? null) ? $change['after'] : json_encode($change['after'] ?? null) }}
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            @if ($editingRowId === $row['id'])
                                <form wire:submit="saveEdit({{ $row['id'] }})" class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                                    <div class="flex items-center gap-2">
                                        <x-filament::badge color="primary">Correction</x-filament::badge>
                                        <p class="text-sm font-semibold text-gray-950 dark:text-white">Correct this row</p>
                                    </div>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                        <label class="block space-y-1">
                                            <span class="text-sm font-medium text-gray-950 dark:text-white">Mapped field</span>
                                            <x-filament::input.wrapper>
                                                <x-filament::input.select wire:model.live="editingField" required aria-label="Field to correct for row {{ $row['source_row_number'] }}">
                                                    <option value="">Choose a field</option>
                                                    @foreach ($this->editableFieldOptions($row['id']) as $name => $label)
                                                        <option value="{{ $name }}">{{ $label }}</option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </label>
                                        <label class="block space-y-1 sm:col-span-2">
                                            <span class="text-sm font-medium text-gray-950 dark:text-white">Corrected value</span>
                                            <x-filament::input.wrapper>
                                                <x-filament::input wire:model="editingValue" aria-label="Corrected value for row {{ $row['source_row_number'] }}" autocomplete="off" />
                                            </x-filament::input.wrapper>
                                        </label>
                                    </div>
                                    <div class="mt-3 flex justify-end gap-2">
                                        <x-filament::button type="button" color="gray" wire:click="cancelEdit">Cancel correction</x-filament::button>
                                        <x-filament::button type="submit">Save correction</x-filament::button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-10 text-center dark:border-white/20 dark:bg-white/5">
                        <x-filament::icon icon="heroicon-o-inbox" class="mx-auto h-8 w-8 text-gray-400" />
                        <p class="mt-3 text-sm font-medium text-gray-950 dark:text-white">No staged rows match these filters</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Adjust the filters or clear them to see all staged rows.</p>
                    </div>
                @endforelse
            </div>

            @if ($review['last_page'] > 1)
                <nav class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-white/10 dark:bg-white/5" aria-label="Staged row pages">
                    <x-filament::button color="gray" size="sm" wire:click="setRowPage({{ max(1, $review['page'] - 1) }})" :disabled="$review['page'] <= 1" aria-label="Previous staged rows">Previous</x-filament::button>
                    <span class="text-sm text-gray-600 dark:text-gray-300" aria-live="polite">Page {{ $review['page'] }} of {{ $review['last_page'] }}</span>
                    <x-filament::button color="gray" size="sm" wire:click="setRowPage({{ min($review['last_page'], $review['page'] + 1) }})" :disabled="$review['page'] >= $review['last_page']" aria-label="Next staged rows">Next</x-filament::button>
                </nav>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
