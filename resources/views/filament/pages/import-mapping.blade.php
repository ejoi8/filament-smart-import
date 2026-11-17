<x-filament-panels::page>
    <div class="space-y-6">
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
            </dl>
        </x-filament::section>

        <form wire:submit="startStaging" class="space-y-6">
            <x-filament::section heading="Choose a worksheet and header">
                <div class="grid gap-6 md:grid-cols-2">
                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-gray-950 dark:text-white">Worksheet</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="sheetIndex" aria-label="Worksheet">
                                @foreach ($sheetOptions as $index => $label)
                                    <option value="{{ $index }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-gray-950 dark:text-white">Header row</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="headerRowValue" aria-label="Header row">
                                <option value="none">No header row</option>
                                @foreach ($previewRows as $row)
                                    <option value="{{ $row['row_number'] }}">Row {{ $row['row_number'] }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                </div>

                @if ($previewMessage !== '')
                    <p class="mt-4 text-sm text-warning-700 dark:text-warning-300" role="status">{{ $previewMessage }}</p>
                @endif
            </x-filament::section>

            <x-filament::section heading="Map source columns">
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    Suggestions are based on the importer field names and aliases. Review each column and choose a field or explicitly ignore it.
                </p>

                <div class="space-y-4">
                    @foreach ($headerCells as $sourceColumn => $headerValue)
                        <label class="grid gap-2 md:grid-cols-2 md:items-center" for="mapping-{{ $sourceColumn }}">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">
                                Column {{ $sourceColumn }}: {{ $headerValue === null || $headerValue === '' ? 'No header' : $headerValue }}
                            </span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="mapping-{{ $sourceColumn }}" wire:model="mapping.{{ $sourceColumn }}" aria-label="Mapping for column {{ $sourceColumn }}">
                                    <option value="">Choose a field or ignore this column</option>
                                    @foreach ($fieldOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            @error('mapping.'.$sourceColumn)
                                <span class="text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</span>
                            @enderror
                        </label>
                    @endforeach
                </div>

                @error('mapping')
                    <p class="mt-4 text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</p>
                @enderror

                <div class="mt-6 flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-o-play">
                        Start validation staging
                    </x-filament::button>
                </div>
            </x-filament::section>
        </form>

        <x-filament::section heading="Sample rows">
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200 text-left text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">Source row</th>
                            @foreach ($headerCells as $sourceColumn => $headerValue)
                                <th scope="col" class="px-3 py-2 font-medium">{{ $headerValue === null || $headerValue === '' ? 'Column '.$sourceColumn : $headerValue }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($previewRows as $row)
                            <tr>
                                <th scope="row" class="px-3 py-2 font-medium">{{ $row['row_number'] }}</th>
                                @foreach ($headerCells as $sourceColumn => $_headerValue)
                                    <td class="max-w-xs truncate px-3 py-2">{{ $row['values'][$sourceColumn] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($headerCells) + 1 }}" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400">No non-empty rows are available in the preview.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
