<?php

namespace SmartImport\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SmartImport\Data\ImportField;
use SmartImport\Services\ImportValueComparator;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\TestCase;

final class ImportValueComparatorTest extends TestCase
{
    #[Test]
    public function explicit_boolean_metadata_compares_only_supported_database_representations(): void
    {
        $comparator = new ImportValueComparator();
        $field = new ImportField('is_active', 'Active', type: 'boolean');

        foreach ([[true, 1], [true, '1'], [1, '1'], [false, 0], [false, '0'], [0, '0']] as [$left, $right]) {
            $this->assertTrue($comparator->sameForField($field, null, $left, $right));
        }

        foreach ([[true, false], [null, ''], ['', '0'], ['false', false], ['yes', true]] as [$left, $right]) {
            $this->assertFalse($comparator->sameForField($field, null, $left, $right));
        }
    }

    #[Test]
    public function native_eloquent_boolean_cast_enables_boolean_comparison_for_other_field_types(): void
    {
        $target = new ImportTargetModel();
        $target->setRawAttributes(['is_active' => 1], true);

        $this->assertTrue((new ImportValueComparator())->sameForField(
            new ImportField('is_active', 'Active'),
            $target,
            $target->getRawOriginal('is_active'),
            true,
        ));
    }

    #[Test]
    public function non_boolean_strings_and_non_boolean_values_keep_strict_semantics(): void
    {
        $comparator = new ImportValueComparator();

        $this->assertFalse($comparator->sameForField(new ImportField('code', 'Code'), null, '001', '1'));
        $this->assertFalse($comparator->sameForField(new ImportField('code', 'Code'), null, null, ''));
        $this->assertTrue($comparator->sameForField(new ImportField('code', 'Code'), null, '001', '001'));
    }
}
