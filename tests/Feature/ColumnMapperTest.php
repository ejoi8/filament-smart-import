<?php

namespace SmartImport\Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;
use SmartImport\Services\ColumnMapper;
use SmartImport\Tests\Fixtures\ColumnMappingDefinition;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\TestCase;

class ColumnMapperTest extends TestCase
{
    #[Test]
    public function exact_names_and_declared_aliases_are_suggested_without_dropping_unmatched_columns(): void
    {
        $result = $this->mapper()->suggest(new ColumnMappingDefinition(), [
            1 => ' Name ',
            2 => 'E-mail',
            3 => 'IDENTITY NUMBER',
            4 => 'Unrecognized value',
        ]);

        $this->assertSame([
            1 => 'name',
            2 => 'email',
            3 => 'no_kp',
        ], $result['mapping']);
        $this->assertSame('exact', $result['columns'][1]['match_type']);
        $this->assertSame('alias', $result['columns'][2]['match_type']);
        $this->assertSame('unmatched', $result['columns'][4]['status']);
        $this->assertSame([4], $result['unmapped_columns']);
    }

    #[Test]
    public function header_detection_skips_introductory_and_data_rows_and_returns_the_best_confident_match(): void
    {
        $result = $this->mapper()->detectHeaderRow(new ColumnMappingDefinition(), [
            ['row_number' => 1, 'values' => [1 => 'Monthly contact report']],
            ['row_number' => 2, 'values' => [1 => 'Name', 2 => 'E-mail']],
            ['row_number' => 3, 'values' => [1 => 'Name', 2 => 'Email', 3 => 'National ID']],
            ['row_number' => 4, 'values' => [1 => 'Ada Lovelace', 2 => 'ada@example.test', 3 => '0012']],
        ]);

        $this->assertSame([
            'row_number' => 3,
            'matched_fields' => ['name', 'email', 'no_kp'],
            'required_fields_matched' => 1,
            'exact_matches' => 2,
        ], $result);
    }

    #[Test]
    public function header_detection_fails_closed_when_required_fields_are_missing_or_colliding(): void
    {
        $result = $this->mapper()->detectHeaderRow(new ColumnMappingDefinition(), [
            ['row_number' => 1, 'values' => [1 => 'Name', 2 => 'Name', 3 => 'Email']],
            ['row_number' => 2, 'values' => [1 => 'Ada Lovelace', 2 => 'ada@example.test', 3 => '0012']],
        ]);

        $this->assertNull($result);
    }

    #[Test]
    public function normalization_handles_punctuation_case_and_unicode_when_available(): void
    {
        if (class_exists(\Normalizer::class)) {
            $definition = $this->definition([new ImportField('café_name', 'Cafe name')]);
            $header = "CAFE\u{0301} NAME";
            $expectedField = 'café_name';
        } else {
            $definition = $this->definition([new ImportField('no_kp', 'National ID')]);
            $header = 'NO.KP';
            $expectedField = 'no_kp';
        }

        $result = $this->mapper()->suggest($definition, [1 => $header]);

        $this->assertSame([1 => $expectedField], $result['mapping']);
        $this->assertSame('suggested', $result['columns'][1]['status']);
    }

    #[Test]
    public function unicode_case_folding_matches_equivalent_field_names(): void
    {
        $definition = $this->definition([new ImportField('strasse', 'Street')]);

        $result = $this->mapper()->suggest($definition, [1 => 'Straße']);

        $this->assertSame([1 => 'strasse'], $result['mapping']);
    }

    #[Test]
    public function equivalent_source_headers_are_flagged_and_not_auto_mapped(): void
    {
        $result = $this->mapper()->suggest(new ColumnMappingDefinition(), [
            1 => 'NO.KP',
            2 => 'No KP',
        ]);

        $this->assertSame([], $result['mapping']);
        $this->assertSame(['header_collision', 'header_collision'], array_column($result['columns'], 'status'));
        $this->assertSame([1, 2], $result['unmapped_columns']);
        $this->assertSame('duplicate_normalized_headers', $result['issues'][0]['code']);
        $this->assertSame([1, 2], $result['issues'][0]['source_columns']);
    }

    #[Test]
    public function shared_aliases_are_ambiguous_and_require_manual_resolution(): void
    {
        $definition = $this->definition([
            new ImportField('employee_id', 'Employee ID', aliases: ['Shared code']),
            new ImportField('department_id', 'Department ID', aliases: ['Shared code']),
        ]);

        $result = $this->mapper()->suggest($definition, [1 => 'Shared code']);

        $this->assertSame([], $result['mapping']);
        $this->assertSame('ambiguous', $result['columns'][1]['status']);
        $this->assertSame(['employee_id', 'department_id'], $result['columns'][1]['candidates']);
        $this->assertSame('ambiguous_field_match', $result['issues'][0]['code']);
    }

    #[Test]
    public function equivalent_normalized_field_names_are_not_guessed_between(): void
    {
        $definition = $this->definition([
            new ImportField('first_name', 'First name'),
            new ImportField('first-name', 'First name alternate'),
        ]);

        $result = $this->mapper()->suggest($definition, [1 => 'First name']);

        $this->assertSame([], $result['mapping']);
        $this->assertSame('ambiguous', $result['columns'][1]['status']);
        $this->assertSame(['first_name', 'first-name'], $result['columns'][1]['candidates']);
    }

    #[Test]
    public function multiple_distinct_columns_suggesting_one_field_are_flagged_as_a_collision(): void
    {
        $result = $this->mapper()->suggest(new ColumnMappingDefinition(), [
            1 => 'name',
            2 => 'Applicant',
        ]);

        $this->assertSame([], $result['mapping']);
        $this->assertSame(['field_collision', 'field_collision'], array_column($result['columns'], 'status'));
        $this->assertSame('multiple_columns_for_field', $result['issues'][0]['code']);
        $this->assertSame([1, 2], $result['issues'][0]['source_columns']);
    }

    #[Test]
    public function exact_field_match_takes_priority_over_an_alias_on_another_field(): void
    {
        $definition = $this->definition([
            new ImportField('name', 'Name'),
            new ImportField('email', 'Email', aliases: ['Name']),
        ]);

        $result = $this->mapper()->suggest($definition, [1 => 'NAME']);

        $this->assertSame([1 => 'name'], $result['mapping']);
        $this->assertSame('exact', $result['columns'][1]['match_type']);
    }

    #[Test]
    public function manual_mapping_uses_source_indexes_and_requires_explicit_ignore_choices(): void
    {
        $result = $this->mapper()->validateManualMapping(
            new ColumnMappingDefinition(),
            [1 => 'Name', 2 => 'Name', 3 => null],
            [1 => 'name', 2 => null, 3 => 'email'],
        );

        $this->assertSame([1 => 'name', 3 => 'email'], $result['mapping']);
        $this->assertSame([2], $result['ignored_columns']);
    }

    #[Test]
    public function manual_mapping_rejects_missing_columns_unknown_fields_and_duplicate_targets(): void
    {
        $mapper = $this->mapper();
        $definition = new ColumnMappingDefinition();
        $headers = [1 => 'Name', 2 => 'Email'];

        $this->assertMappingRejected($mapper, $definition, $headers, [1 => 'name'], 'needs a field choice');
        $this->assertMappingRejected($mapper, $definition, $headers, [1 => 'admin', 2 => null], 'is not allowed');
        $this->assertMappingRejected($mapper, $definition, $headers, [1 => 'name', 2 => 'name'], 'assigned to more than one');
        $this->assertMappingRejected($mapper, $definition, $headers, [1 => 'name', 2 => null, 3 => null], 'unknown source column');
        $this->assertMappingRejected($mapper, $definition, $headers, [1 => true, 2 => null], 'must select an allowed field or null');
    }

    #[Test]
    public function invalid_header_keys_and_invalid_field_aliases_are_rejected(): void
    {
        try {
            $this->mapper()->suggest(new ColumnMappingDefinition(), [0 => 'Name']);
            $this->fail('Source columns must use one-based indexes.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('positive, one-based', $exception->getMessage());
        }

        try {
            new ImportField('name', 'Name', aliases: ['Name', 'name']);
            $this->fail('Case-insensitive duplicate aliases must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('unique within a field', $exception->getMessage());
        }
    }

    #[Test]
    public function invalid_utf8_headers_are_reported_and_can_still_be_manually_mapped_by_index(): void
    {
        $definition = new ColumnMappingDefinition();
        $headers = [1 => "\xFF"];
        $mapper = $this->mapper();

        $suggestions = $mapper->suggest($definition, $headers);
        $manual = $mapper->validateManualMapping($definition, $headers, [1 => 'name']);

        $this->assertSame('invalid_header', $suggestions['columns'][1]['status']);
        $this->assertSame('invalid_header_encoding', $suggestions['issues'][0]['code']);
        $this->assertSame([1 => 'name'], $manual['mapping']);
    }

    private function mapper(): ColumnMapper
    {
        return $this->app->make(ColumnMapper::class);
    }

    /** @param list<ImportField> $fields */
    private function definition(array $fields): ImportDefinition
    {
        return new class($fields) implements ImportDefinition
        {
            /** @param list<ImportField> $fields */
            public function __construct(private array $fields) {}

            public function key(): string
            {
                return 'mapping-test';
            }

            public function version(): string
            {
                return '1';
            }

            public function label(): string
            {
                return 'Mapping test';
            }

            public function targetModel(): string
            {
                return ImportTargetModel::class;
            }

            public function fields(): array
            {
                return $this->fields;
            }

            public function uniqueKeyFields(): array
            {
                return [];
            }

            public function writePolicy(): ImportWritePolicy
            {
                return new ImportWritePolicy(ImportMode::Insert, insertConflict: WriteConflictPolicy::Skip);
            }
        };
    }

    /** @param array<int, string|int|float|bool|null> $headers @param array<int, string|null> $mapping */
    private function assertMappingRejected(
        ColumnMapper $mapper,
        ImportDefinition $definition,
        array $headers,
        array $mapping,
        string $message,
    ): void {
        try {
            $mapper->validateManualMapping($definition, $headers, $mapping);
            $this->fail('Invalid manual mapping must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
