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

        @if ($isCsv)
            <x-filament::section heading="CSV reading settings">
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">These settings apply to the sample and the background staging worker.</p>
                <div class="grid gap-6 md:grid-cols-2">
                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-gray-950 dark:text-white">Delimiter</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="csvDelimiterValue" aria-label="CSV delimiter">
                                <option value="auto">Detect automatically</option>
                                @foreach (config('smart-import.reading.csv_delimiters', []) as $delimiter => $label)
                                    <option value="{{ $delimiter }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-gray-950 dark:text-white">Text encoding</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="csvEncodingValue" aria-label="CSV text encoding">
                                <option value="guess">Detect automatically</option>
                                @foreach (config('smart-import.reading.csv_encodings', []) as $encoding)
                                    <option value="{{ $encoding }}">{{ $encoding }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                </div>
            </x-filament::section>
        @endif

        @if ($importerOptionDefinitions !== [])
            <x-filament::section heading="Importer settings">
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">These choices are defined and checked by this importer. They are saved with the staged revision.</p>
                <div class="grid gap-6 md:grid-cols-2">
                    @foreach ($importerOptionDefinitions as $key => $option)
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $option['label'] }}</span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model.live="importerOptions.{{ $key }}" aria-label="{{ $option['label'] }}">
                                    @foreach ($option['choices'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Reusable mapping presets">
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">Presets are tied to this importer, worksheet, header, and CSV settings. Applying one replaces the current column choices, so review them before staging.</p>
            <div class="grid gap-6 md:grid-cols-2">
                <label class="block space-y-2">
                    <span class="text-sm font-medium text-gray-950 dark:text-white">Saved preset</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="selectedPresetId" aria-label="Saved mapping preset">
                            @foreach ($presetSelectOptions as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <div class="flex items-end">
                    <x-filament::button type="button" color="gray" wire:click="applySelectedPreset" :disabled="! ($presetCompatibility['compatible'] ?? false)">
                        Apply preset
                    </x-filament::button>
                </div>
                <label class="block space-y-2">
                    <span class="text-sm font-medium text-gray-950 dark:text-white">Save current mapping as</span>
                    <x-filament::input.wrapper>
                        <x-filament::input wire:model="presetName" maxlength="120" aria-label="Mapping preset name" />
                    </x-filament::input.wrapper>
                </label>
                <div class="flex items-end">
                    <x-filament::button type="button" color="gray" wire:click="saveMappingPreset">
                        Save mapping preset
                    </x-filament::button>
                </div>
            </div>
            @if ($presetMessage !== '')
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400" role="status">{{ $presetMessage }}</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Importer processing rules">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $processingSummary }}</p>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">These rules are fixed by the registered importer. The form cannot change its write mode, matching key, or blank handling.</p>
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
                                <option value="custom">Another row…</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>

                    @if ($headerRowValue === 'custom')
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">Header row number</span>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" min="1" max="{{ $sheetRowCount }}" wire:model.live.debounce.500ms="customHeaderRowValue" aria-label="Header row number" />
                            </x-filament::input.wrapper>
                        </label>
                    @endif
                </div>

                @if ($previewMessage !== '')
                    <p class="mt-4 text-sm text-warning-700 dark:text-warning-300" role="status">{{ $previewMessage }}</p>
                @endif

                @if ($headerSuggestion !== null && (string) $headerSuggestion['row_number'] !== $headerRowValue)
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-primary-50 p-3 text-sm text-primary-800 dark:bg-primary-400/10 dark:text-primary-200" role="status">
                        <p>
                            Likely header: row {{ $headerSuggestion['row_number'] }}
                            ({{ count($headerSuggestion['matched_fields']) }} importer fields matched).
                            Review the suggestion before staging.
                        </p>
                        <x-filament::button type="button" color="gray" wire:click="useDetectedHeaderRow">
                            Use detected header
                        </x-filament::button>
                    </div>
                @elseif ($headerSuggestion === null)
                    <p class="mt-4 text-sm text-gray-600 dark:text-gray-400" role="status">
                        No confident header was found in this sample. Choose a header row or select “No header row”.
                    </p>
                @endif
            </x-filament::section>

            <x-filament::section heading="Map source columns">
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    Auto-detected mappings are highlighted in green. Suggestions are based on the importer field names and aliases; review each column and choose a field or explicitly ignore it.
                </p>

                @if ($errors->any())
                    <p class="mb-4 text-sm text-danger-600 dark:text-danger-400" role="alert">
                        Some source columns still need a mapping choice. Review the errors below and try again.
                    </p>
                @endif

                <div class="space-y-4">
                    @foreach ($headerCells as $sourceColumn => $headerValue)
                        @php($isAutoMapped = isset($suggestedMappings[$sourceColumn]) && ($mapping[$sourceColumn] ?? null) === $suggestedMappings[$sourceColumn])
                        <label
                            class="grid gap-2 rounded-lg px-3 py-2 md:grid-cols-2 md:items-center"
                            @if ($isAutoMapped) style="background-color: rgba(34, 197, 94, 0.10); box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.35);" @endif
                            for="mapping-{{ $sourceColumn }}"
                        >
                            <span class="text-sm font-medium text-gray-950 dark:text-white">
                                Column {{ $sourceColumn }}: {{ $headerValue === null || $headerValue === '' ? 'No header' : $headerValue }}
                                @if ($isAutoMapped)
                                    <x-filament::badge color="success" class="ms-2">Auto-detected</x-filament::badge>
                                @endif
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

                <div class="mt-6 flex items-center justify-end gap-3">
                    <span wire:loading wire:target="startStaging" class="text-sm text-gray-500 dark:text-gray-400" role="status">
                        Starting validation staging…
                    </span>
                    <x-filament::button type="submit" icon="heroicon-o-play" wire:loading.attr="disabled" wire:target="startStaging">
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
