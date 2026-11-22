<?php

namespace SmartImport\Console\Commands;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use SmartImport\Services\ConsoleActorResolver;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\MappingPresetService;
use SmartImport\Services\SpreadsheetReader;
use SmartImport\Services\ImportStagingService;
use Throwable;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

final class StageImportCommand extends Command
{
    protected $signature = 'smart-import:stage
        {session : Existing authorized Smart Import session ID}
        {--actor= : Required actor ID resolved through the selected guard}
        {--guard= : Required authentication guard name}
        {--importer= : Registered importer key}
        {--sheet=0 : Zero-based worksheet index}
        {--header-row=1 : One-based header row, or "none"}
        {--mapping= : JSON object from one-based source column indexes to field names or null}
        {--preset= : Authorized saved mapping preset ID (mutually exclusive with --mapping)}
        {--options= : JSON object containing csv_delimiter, csv_encoding, or importer_options}';

    protected $description = 'Validate and stage an existing import session through the shared import engine';

    public function handle(
        ConsoleActorResolver $actors,
        ImportStagingService $staging,
        ImportCoordinator $coordinator,
        SpreadsheetReader $reader,
        MappingPresetService $presets,
    ): int
    {
        try {
            $actor = $actors->resolve((string) $this->option('actor'), (string) $this->option('guard'));
            $importerKey = $this->option('importer');

            if (! is_string($importerKey) || $importerKey === '') {
                throw new InvalidArgumentException('Provide a registered importer with --importer.');
            }

            $sheetIndex = filter_var($this->option('sheet'), FILTER_VALIDATE_INT);

            if (! is_int($sheetIndex) || $sheetIndex < 0) {
                throw new InvalidArgumentException('--sheet must be a zero-based non-negative integer.');
            }

            $headerOption = (string) $this->option('header-row');
            $headerRow = $headerOption === 'none' ? null : filter_var($headerOption, FILTER_VALIDATE_INT);

            if ($headerOption !== 'none' && (! is_int($headerRow) || $headerRow < 1)) {
                throw new InvalidArgumentException('--header-row must be a positive one-based integer or "none".');
            }

            $options = $this->jsonObject((string) $this->option('options'), 'options', allowEmpty: true);
            $mappingInput = $this->option('mapping');
            $presetId = $this->option('preset');

            if (is_string($mappingInput) === is_string($presetId)) {
                throw new InvalidArgumentException('Provide exactly one of --mapping or --preset.');
            }

            if (is_string($mappingInput)) {
                $mapping = $this->sourceColumnMapping($this->jsonObject($mappingInput, 'mapping'));
            } else {
                [$mapping, $options] = $this->resolvePresetMapping(
                    (string) $this->argument('session'),
                    $actor,
                    $importerKey,
                    $sheetIndex,
                    $headerRow,
                    (string) $presetId,
                    $options,
                    $coordinator,
                    $reader,
                    $presets,
                );
            }

            $session = $staging->start(
                (string) $this->argument('session'),
                $actor,
                $importerKey,
                $sheetIndex,
                $headerRow,
                $mapping,
                $options,
            );

            $this->json([
                'ok' => true,
                'session_id' => (string) $session->getKey(),
                'status' => $session->status?->value,
                'mapping_revision' => $session->mapping_revision,
                'staging_revision' => $session->staging_revision,
            ]);

            return self::SUCCESS;
        } catch (AuthorizationException) {
            return $this->failure('The selected actor is not authorized to stage this import.', 3);
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 2);
        } catch (Throwable) {
            return $this->failure('Staging could not be queued. Check the import session and queue configuration.', self::FAILURE);
        }
    }

    private function resolvePresetMapping(
        string $sessionId,
        \Illuminate\Contracts\Auth\Authenticatable $actor,
        string $importerKey,
        int $sheetIndex,
        ?int $headerRow,
        string $presetId,
        array $options,
        ImportCoordinator $coordinator,
        SpreadsheetReader $reader,
        MappingPresetService $presets,
    ): array {
        if ($presetId === '' || array_key_exists('importer_options', $options)
            || array_diff(array_keys($options), ['csv_delimiter', 'csv_encoding']) !== []) {
            throw new InvalidArgumentException('Preset staging accepts only CSV reader settings in --options; importer choices come from the saved preset.');
        }

        $delimiter = $options['csv_delimiter'] ?? null;
        $encoding = $options['csv_encoding'] ?? Csv::GUESS_ENCODING;

        if (($delimiter !== null && ! is_string($delimiter)) || ! is_string($encoding)) {
            throw new InvalidArgumentException('Preset CSV settings must use a string delimiter and encoding.');
        }

        $readerOptions = $reader->normalizeCsvOptions($delimiter, $encoding);
        $session = $coordinator->get($sessionId, $actor);
        $sheets = $reader->listSheets($session, $readerOptions['csv_delimiter'], $readerOptions['csv_encoding']);

        if (! array_key_exists($sheetIndex, $sheets)) {
            throw new InvalidArgumentException('The selected worksheet does not exist in this file.');
        }

        $sheet = $sheets[$sheetIndex];

        if ($headerRow === null) {
            $headers = array_fill_keys(range(1, $sheet['columns']), null);
        } else {
            $preview = $reader->preview(
                $session,
                $sheetIndex,
                $headerRow,
                1,
                $headerRow,
                $readerOptions['csv_delimiter'],
                $readerOptions['csv_encoding'],
            );
            $headers = $preview['headers'];
        }

        if (! is_array($headers)) {
            throw new InvalidArgumentException('The selected source header could not be read for preset validation.');
        }

        $resolved = $presets->resolve(
            $presetId,
            $importerKey,
            $sheetIndex,
            $sheet['name'],
            $headerRow,
            $headers,
            $actor,
            ['reader' => $readerOptions],
        );

        if (! $resolved['compatible']) {
            throw new DomainException(sprintf('The mapping preset is incompatible with this source (%s).', $resolved['incompatibility']));
        }

        return [
            $resolved['mapping'],
            [
                ...$readerOptions,
                'importer_options' => $resolved['options']['importer'] ?? [],
            ],
        ];
    }

    private function jsonObject(string $json, string $name, bool $allowEmpty = false): array
    {
        if (strlen($json) > 65536 || ($json === '' && ! $allowEmpty)) {
            throw new InvalidArgumentException(sprintf('Provide a JSON object for --%s of at most 64 KB.', $name));
        }

        try {
            $decoded = json_decode($json === '' ? '{}' : $json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException(sprintf('--%s must contain valid JSON.', $name), 0, $exception);
        }

        if (! is_object($decoded)) {
            throw new InvalidArgumentException(sprintf('--%s must be a JSON object.', $name));
        }

        return json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    }

    private function sourceColumnMapping(array $mapping): array
    {
        $validated = [];

        foreach ($mapping as $column => $field) {
            if ((! is_int($column) && (! is_string($column) || preg_match('/\A[1-9][0-9]*\z/D', $column) !== 1))
                || (int) $column < 1
                || ($field !== null && ! is_string($field))) {
                throw new InvalidArgumentException('Mapping keys must be positive source column numbers and values must be field names or null.');
            }

            $validated[(int) $column] = $field;
        }

        if ($validated === []) {
            throw new InvalidArgumentException('The mapping JSON must include every selected source column.');
        }

        return $validated;
    }

    private function json(array $data): void
    {
        $this->line(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function failure(string $message, int $code): int
    {
        $this->json(['ok' => false, 'error' => $message]);

        return $code;
    }
}
