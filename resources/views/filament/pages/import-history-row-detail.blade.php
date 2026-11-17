<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Row outcome">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Sheet</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $rowDetail['source_sheet'] === '' ? '—' : $rowDetail['source_sheet'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Source row</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $rowDetail['source_row_number'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Validation</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ str($rowDetail['validation_status'])->headline() }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Result</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ str($rowDetail['execution_status'])->headline() }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Target match</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $rowDetail['change_type'] === null ? '—' : str($rowDetail['change_type'])->headline() }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Planned action</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $rowDetail['planned_action'] === null ? '—' : str($rowDetail['planned_action'])->headline() }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Warnings</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $rowDetail['warning_count'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Attempts</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ count($rowDetail['attempts']) }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Target references">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Preview target</dt>
                    <dd class="mt-1 break-all text-sm text-gray-950 dark:text-white">
                        @if ($rowDetail['preview_target']['type'] === null && $rowDetail['preview_target']['id'] === null)
                            No existing target was matched.
                        @else
                            {{ $rowDetail['preview_target']['type'] ?? 'Unknown type' }} #{{ $rowDetail['preview_target']['id'] ?? '—' }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Recorded outcome target</dt>
                    <dd class="mt-1 break-all text-sm text-gray-950 dark:text-white">
                        @if ($rowDetail['outcome_target']['type'] === null && $rowDetail['outcome_target']['id'] === null)
                            No target record was written.
                        @else
                            {{ $rowDetail['outcome_target']['type'] ?? 'Unknown type' }} #{{ $rowDetail['outcome_target']['id'] ?? '—' }}
                        @endif
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        @if ($rowDetail['changes'] !== [])
            <x-filament::section heading="Before and after">
                <div class="overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="px-3 py-2 font-medium">Field</th>
                                <th class="px-3 py-2 font-medium">Before</th>
                                <th class="px-3 py-2 font-medium">After</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($rowDetail['changes'] as $change)
                                <tr class="align-top text-gray-950 dark:text-white">
                                    <th scope="row" class="max-w-64 px-3 py-2 text-left font-medium">
                                        {{ $change['label'] }}
                                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $change['field'] }}</span>
                                    </th>
                                    <td class="max-w-xl whitespace-pre-wrap break-words px-3 py-2">
                                        {{ $change['before_exists'] ? $change['before'] : '— (new value)' }}
                                        @if ($change['redacted'])
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">Sensitive field</span>
                                        @endif
                                    </td>
                                    <td class="max-w-xl whitespace-pre-wrap break-words px-3 py-2">
                                        {{ $change['after'] }}
                                        @if ($change['redacted'])
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">Sensitive field</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        @if ($rowDetail['validation_errors'] !== [] || $rowDetail['execution_error'] !== null)
            <x-filament::section heading="Safe error details">
                @if ($rowDetail['execution_error'] !== null)
                    <p class="mb-3 text-sm text-gray-700 dark:text-gray-300">
                        {{ $rowDetail['execution_error']['message'] }}
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ str($rowDetail['execution_error']['code'])->headline() }}</span>
                    </p>
                @endif
                @if ($rowDetail['validation_errors'] !== [])
                    <ul class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($rowDetail['validation_errors'] as $error)
                            <li class="py-3 first:pt-0 last:pb-0">
                                <p class="text-sm text-gray-950 dark:text-white">
                                    {{ $error['field'] ?? 'Row' }} · Source row {{ $error['source_row'] }}: {{ $error['message'] }}
                                </p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ str($error['code'])->headline() }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endif

        @if ($rowDetail['attempts'] !== [])
            <x-filament::section heading="Attempt outcomes">
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rowDetail['attempts'] as $attempt)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <span class="text-sm text-gray-950 dark:text-white">
                                Attempt {{ $attempt['attempt'] ?? '—' }} · {{ str($attempt['status'])->headline() }}
                            </span>
                            <span class="break-all text-sm text-gray-600 dark:text-gray-300">
                                @if ($attempt['target_type'] !== null || $attempt['target_id'] !== null)
                                    {{ $attempt['target_type'] ?? 'Unknown type' }} #{{ $attempt['target_id'] ?? '—' }}
                                @else
                                    No target recorded
                                @endif
                                @if ($attempt['error_code'] !== null)
                                    · {{ str($attempt['error_code'])->headline() }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
