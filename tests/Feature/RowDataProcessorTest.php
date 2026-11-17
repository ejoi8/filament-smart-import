<?php

namespace SmartImport\Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SmartImport\Contracts\FieldTransformer;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\BlankCellPolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;
use SmartImport\Services\RowDataProcessor;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\TestCase;

class RowDataProcessorTest extends TestCase
{
    #[Test]
    public function built_in_and_custom_transformers_run_in_declared_order_and_preserve_decimal_precision(): void
    {
        $definition = $this->definition([
            new ImportField(
                'name',
                'Name',
                transformers: [
                    'trim',
                    new class implements FieldTransformer
                    {
                        public function transform(mixed $value): mixed
                        {
                            return is_string($value) ? strtoupper($value) : $value;
                        }
                    },
                ],
            ),
            new ImportField('amount', 'Amount', rules: ['numeric'], transformers: ['number']),
            new ImportField('joined_on', 'Joined on', rules: ['date_format:Y-m-d'], transformers: ['date']),
        ]);

        $result = $this->processor()->process(
            $definition,
            [1 => '  Ada Lovelace  ', 2 => '+00012345678901234567890.3400', 3 => '2024-02-29'],
            [1 => 'name', 2 => 'amount', 3 => 'joined_on'],
            12,
        );

        $this->assertSame('  Ada Lovelace  ', $result['mapped_values']['name']);
        $this->assertSame('ADA LOVELACE', $result['normalized_values']['name']);
        $this->assertSame('12345678901234567890.34', $result['normalized_values']['amount']);
        $this->assertSame('2024-02-29', $result['normalized_values']['joined_on']);
        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['valid']);
    }

    #[Test]
    public function laravel_field_rules_return_field_code_safe_message_and_source_row(): void
    {
        $definition = $this->definition([
            new ImportField('name', 'Full name', required: true, rules: ['min:2']),
            new ImportField('email', 'Email address', rules: ['email']),
        ]);

        $result = $this->processor()->process(
            $definition,
            [1 => 'A', 2 => 'not-an-email'],
            [1 => 'name', 2 => 'email'],
            23,
        );

        $this->assertFalse($result['valid']);
        $this->assertSame([
            ['field' => 'name', 'code' => 'min', 'source_row' => 23],
            ['field' => 'email', 'code' => 'email', 'source_row' => 23],
        ], array_map(
            static fn (array $error): array => [
                'field' => $error['field'],
                'code' => $error['code'],
                'source_row' => $error['source_row'],
            ],
            $result['errors'],
        ));
        $this->assertNotSame('', $result['errors'][0]['safe_message']);
        $this->assertStringContainsString('Full name', $result['errors'][0]['safe_message']);
    }

    #[Test]
    public function required_fields_are_validated_even_when_unmapped_and_missing_cells_stay_absent(): void
    {
        $definition = $this->definition([
            new ImportField('name', 'Name', required: true),
            new ImportField('nickname', 'Nickname'),
        ]);

        $result = $this->processor()->process($definition, [1 => 'extra'], [1 => null], 4);

        $this->assertSame(['name'], array_column($result['errors'], 'field'));
        $this->assertSame('required', $result['errors'][0]['code']);
        $this->assertArrayNotHasKey('nickname', $result['normalized_values']);
        $this->assertArrayNotHasKey('name', $result['mapped_values']);
    }

    #[Test]
    public function blank_values_preserve_existing_data_by_default_while_missing_cells_remain_unmapped(): void
    {
        $definition = $this->definition([
            new ImportField('missing', 'Missing'),
            new ImportField('empty', 'Empty'),
            new ImportField('whitespace', 'Whitespace'),
            new ImportField('null_value', 'Null value'),
        ]);

        $result = $this->processor()->process(
            $definition,
            [2 => '', 3 => " \t ", 4 => null],
            [1 => 'missing', 2 => 'empty', 3 => 'whitespace', 4 => 'null_value'],
            5,
        );

        $this->assertArrayNotHasKey('missing', $result['mapped_values']);
        $this->assertSame('', $result['mapped_values']['empty']);
        $this->assertSame(" \t ", $result['mapped_values']['whitespace']);
        $this->assertNull($result['mapped_values']['null_value']);
        $this->assertSame([], $result['normalized_values']);
        $this->assertTrue($result['valid']);
    }

    #[Test]
    public function blank_policy_can_write_empty_string_or_null_and_null_markers_are_explicit(): void
    {
        $definition = $this->definition([
            new ImportField('keep', 'Keep'),
            new ImportField('empty', 'Empty', blankCellPolicy: BlankCellPolicy::SetEmptyString),
            new ImportField('cleared', 'Cleared', blankCellPolicy: BlankCellPolicy::SetNull),
            new ImportField('marker', 'Marker', rules: ['nullable'], nullMarkers: ['[NULL]']),
            new ImportField('literal', 'Literal'),
        ]);

        $result = $this->processor()->process(
            $definition,
            [1 => '', 2 => '  ', 3 => '', 4 => '[NULL]', 5 => 'NULL'],
            [1 => 'keep', 2 => 'empty', 3 => 'cleared', 4 => 'marker', 5 => 'literal'],
            6,
        );

        $this->assertArrayNotHasKey('keep', $result['normalized_values']);
        $this->assertSame('', $result['normalized_values']['empty']);
        $this->assertArrayHasKey('cleared', $result['normalized_values']);
        $this->assertNull($result['normalized_values']['cleared']);
        $this->assertArrayHasKey('marker', $result['normalized_values']);
        $this->assertNull($result['normalized_values']['marker']);
        $this->assertSame('[NULL]', $result['mapped_values']['marker']);
        $this->assertSame('NULL', $result['normalized_values']['literal']);
        $this->assertTrue($result['valid']);
    }

    #[Test]
    public function null_markers_must_be_unique_non_empty_trimmed_strings(): void
    {
        foreach ([['[NULL]', '[NULL]'], [''], [' [NULL] '], [1]] as $markers) {
            try {
                new ImportField('value', 'Value', nullMarkers: $markers);
                $this->fail('Invalid null marker definitions must be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function invalid_transform_values_produce_redacted_field_errors_and_skip_rules_for_that_field(): void
    {
        $definition = $this->definition([
            new ImportField(
                'account_number',
                'Account number',
                required: true,
                rules: ['numeric'],
                transformers: [new class implements FieldTransformer
                {
                    public function transform(mixed $value): mixed
                    {
                        throw new RuntimeException('SQLSTATE[secret]: private parser detail');
                    }
                }],
            ),
        ]);

        $result = $this->processor()->process($definition, [1 => 'sensitive-value'], [1 => 'account_number'], 8);

        $this->assertSame([
            [
                'field' => 'account_number',
                'code' => 'transform_failed',
                'safe_message' => 'The value could not be transformed for this field.',
                'source_row' => 8,
            ],
        ], $result['errors']);
        $this->assertArrayNotHasKey('account_number', $result['normalized_values']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result['errors'], JSON_THROW_ON_ERROR));
        $this->assertFalse($result['valid']);
    }

    #[Test]
    public function decimal_transformer_preserves_precision_and_blank_policies_are_explicit(): void
    {
        $definition = $this->definition([
            new ImportField('amount', 'Amount', transformers: ['number']),
            new ImportField('empty', 'Empty', transformers: ['number'], blankCellPolicy: BlankCellPolicy::SetEmptyString),
            new ImportField('whitespace', 'Whitespace', transformers: ['number'], blankCellPolicy: BlankCellPolicy::SetEmptyString),
            new ImportField('null_value', 'Null value', transformers: ['number'], blankCellPolicy: BlankCellPolicy::SetNull),
        ]);

        $result = $this->processor()->process(
            $definition,
            [1 => ' 001.2500e+003 ', 2 => '', 3 => '  ', 4 => null],
            [1 => 'amount', 2 => 'empty', 3 => 'whitespace', 4 => 'null_value'],
            1,
        );

        $this->assertSame('1.25e3', $result['normalized_values']['amount']);
        $this->assertSame('', $result['normalized_values']['empty']);
        $this->assertSame('', $result['normalized_values']['whitespace']);
        $this->assertNull($result['normalized_values']['null_value']);
        $this->assertTrue($result['valid']);
    }

    #[Test]
    public function invalid_dates_and_mappings_are_rejected_safely(): void
    {
        $definition = $this->definition([
            new ImportField('joined_on', 'Joined on', transformers: ['date']),
        ]);
        $result = $this->processor()->process($definition, [1 => '2024-02-30'], [1 => 'joined_on'], 3);

        $this->assertSame('transform_failed', $result['errors'][0]['code']);
        $this->assertSame('The value could not be transformed for this field.', $result['errors'][0]['safe_message']);

        $this->expectException(InvalidArgumentException::class);
        $this->processor()->process($definition, [1 => '2024-02-29'], [1 => 'unregistered'], 3);
    }

    #[Test]
    public function import_field_rejects_unknown_transformer_metadata(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ImportField('name', 'Name', transformers: ['strnig']);
    }

    private function processor(): RowDataProcessor
    {
        return $this->app->make(RowDataProcessor::class);
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
                return 'row-data-test';
            }

            public function version(): string
            {
                return '1';
            }

            public function label(): string
            {
                return 'Row data test';
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
}
