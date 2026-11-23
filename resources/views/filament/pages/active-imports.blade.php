<x-filament-panels::page class="smart-import">
    @php($pollSeconds = max(1, min(60, (int) config('smart-import.progress.polling_interval_seconds', 2))))
    @if ($shouldPoll)
        <div wire:poll.{{ $pollSeconds }}s="refreshSessions" class="space-y-6">
    @else
        <div class="space-y-6">
    @endif
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Progress is saved as each row is committed. Imports keep running when you leave this page.
        </p>

        @forelse ($sessions as $session)
            <x-filament::section>
                <div class="space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                                <a
                                    href="{{ \SmartImport\Filament\Pages\ImportProgressPage::getUrl(['session' => $session['id']]) }}"
                                    class="text-primary-600 hover:underline dark:text-primary-400"
                                >
                                    {{ $session['filename'] }}
                                </a>
                            </h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ str($session['status'])->headline() }}
                                · {{ str($session['source'])->headline() }}
                                · Attempt {{ $session['attempt'] }}
                            </p>
                        </div>
                        <a
                            href="{{ \SmartImport\Filament\Pages\ImportProgressPage::getUrl(['session' => $session['id']]) }}"
                            class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            View progress
                        </a>
                    </div>

                    <div class="space-y-2">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $session['progress']['percentage'] }}%</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $session['progress']['processed'] }} of {{ $session['progress']['total'] }} {{ $session['progress']['label'] }}
                            </span>
                        </div>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
                            role="progressbar"
                            aria-label="Progress for {{ $session['filename'] }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="{{ $session['progress']['percentage'] }}"
                        >
                            <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $session['progress']['percentage'] }}%"></div>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Last activity:
                        {{ $session['last_activity'] === null ? '—' : \Illuminate\Support\Carbon::parse($session['last_activity'])->format('M j, Y H:i:s') }}
                    </p>
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">There are no active imports right now.</p>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
