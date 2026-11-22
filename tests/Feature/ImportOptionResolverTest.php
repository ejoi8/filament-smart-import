<?php

namespace SmartImport\Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Contracts\ImportDefinition;
use SmartImport\Contracts\ImportOptionProvider;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportOption;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Services\ImportOptionResolver;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\TestCase;

class ImportOptionResolverTest extends TestCase
{
    #[Test]
    public function only_importer_declared_choices_are_accepted_and_defaults_are_applied(): void
    {
        $resolver = app(ImportOptionResolver::class);
        $definition = new OptionCapableDefinition();

        $this->assertSame(['duplicate_action' => 'reject'], $resolver->resolve($definition, []));
        $this->assertSame(
            ['duplicate_action' => 'keep_first'],
            $resolver->resolve($definition, ['duplicate_action' => 'keep_first']),
        );

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve($definition, ['duplicate_action' => 'overwrite']);
    }

    #[Test]
    public function importers_without_the_optional_capability_cannot_receive_forged_options(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ImportOptionResolver::class)->resolve(new PlainDefinition(), ['duplicate_action' => 'keep_first']);
    }

    #[Test]
    public function an_explicit_null_choice_is_rejected_instead_of_silently_replaced_with_a_default(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ImportOptionResolver::class)->resolve(new OptionCapableDefinition(), ['duplicate_action' => null]);
    }
}

final class OptionCapableDefinition implements ImportDefinition, ImportOptionProvider
{
    public function key(): string { return 'option-capable'; }
    public function version(): string { return '1'; }
    public function label(): string { return 'Option capable'; }
    public function targetModel(): string { return ImportTargetModel::class; }
    public function fields(): array { return [new ImportField('name', 'Name')]; }
    public function uniqueKeyFields(): array { return []; }
    public function writePolicy(): ImportWritePolicy { return new ImportWritePolicy(ImportMode::Upsert); }

    public function importOptions(): array
    {
        return [new ImportOption('duplicate_action', 'Duplicate handling', [
            'reject' => 'Reject duplicate rows',
            'keep_first' => 'Keep the first row',
        ], 'reject')];
    }
}

final class PlainDefinition implements ImportDefinition
{
    public function key(): string { return 'plain'; }
    public function version(): string { return '1'; }
    public function label(): string { return 'Plain'; }
    public function targetModel(): string { return ImportTargetModel::class; }
    public function fields(): array { return [new ImportField('name', 'Name')]; }
    public function uniqueKeyFields(): array { return []; }
    public function writePolicy(): ImportWritePolicy { return new ImportWritePolicy(ImportMode::Upsert); }
}
