<x-filament-panels::page class="smart-import">
    <div class="space-y-4">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Row values stay hidden unless your account has access to the specific sensitive fields.
        </p>
        <x-filament::section>
            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
