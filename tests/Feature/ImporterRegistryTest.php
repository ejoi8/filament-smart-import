<?php

namespace SmartImport\Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Data\ImportField;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;
use SmartImport\Services\ImporterRegistry;
use SmartImport\Tests\Fixtures\BaseImportDefinition;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\TestCase;

class ImporterRegistryTest extends TestCase
{
    #[Test]
    public function configured_definition_is_resolved_and_registered_by_key(): void
    {
        config()->set('smart-import.importers', [ConfiguredImporter::class]);

        $registry = $this->app->make(ImporterRegistry::class);

        $this->assertTrue($registry->has('contacts'));
        $this->assertInstanceOf(ConfiguredImporter::class, $registry->get('contacts'));
        $this->assertInstanceOf(ConfiguredImporterDependency::class, $registry->get('contacts')->dependency);
        $this->assertSame('Contacts', $registry->get('contacts')->label());
        $this->assertSame('1', $registry->get('contacts')->version());
        $this->assertSame(ImportTargetModel::class, $registry->get('contacts')->targetModel());
        $this->assertSame(['name', 'email'], array_map(
            static fn (ImportField $field): string => $field->name,
            $registry->get('contacts')->fields(),
        ));
        $this->assertSame([$registry->get('contacts')], $registry->all());
    }

    #[Test]
    public function importer_configuration_must_be_an_array(): void
    {
        config()->set('smart-import.importers', 'not-an-array');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an array');

        $this->app->make(ImporterRegistry::class);
    }

    #[Test]
    public function duplicate_keys_are_rejected(): void
    {
        config()->set('smart-import.importers', [
            ConfiguredImporter::class,
            DuplicateKeyImporter::class,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        $this->app->make(ImporterRegistry::class);
    }

    #[Test]
    public function unknown_keys_are_rejected(): void
    {
        $registry = $this->app->make(ImporterRegistry::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not registered');

        $registry->get('missing');
    }

    #[Test]
    public function configured_classes_must_implement_the_definition_contract(): void
    {
        config()->set('smart-import.importers', [\stdClass::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        $this->app->make(ImporterRegistry::class);
    }

    #[Test]
    public function a_target_must_be_an_eloquent_model(): void
    {
        $registry = new ImporterRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an Eloquent model class');

        $registry->register(new class extends BaseImportDefinition
        {
            public function key(): string
            {
                return 'invalid-target';
            }

            public function targetModel(): string
            {
                return \stdClass::class;
            }
        });
    }

    #[Test]
    public function fields_must_be_a_non_empty_list_of_unique_import_fields(): void
    {
        $registry = new ImporterRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('defined more than once');

        $registry->register(new class extends BaseImportDefinition
        {
            public function key(): string
            {
                return 'duplicate-fields';
            }

            public function fields(): array
            {
                return [
                    new ImportField('name', 'Name'),
                    new ImportField('name', 'Alternate name'),
                ];
            }
        });
    }

    #[Test]
    public function unique_key_fields_must_be_allowlisted_and_non_repeating(): void
    {
        $registry = new ImporterRegistry();

        try {
            $registry->register(new class extends BaseImportDefinition
            {
                public function key(): string
                {
                    return 'unknown-unique-field';
                }

                public function uniqueKeyFields(): array
                {
                    return ['missing'];
                }
            });
            $this->fail('A unique key cannot reference an unregistered importer field.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('only defined fields', $exception->getMessage());
        }

        try {
            $registry->register(new class extends BaseImportDefinition
            {
                public function key(): string
                {
                    return 'repeated-unique-field';
                }

                public function uniqueKeyFields(): array
                {
                    return ['email', 'email'];
                }
            });
            $this->fail('A composite unique key cannot repeat a field.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('is repeated', $exception->getMessage());
        }

        try {
            $registry->register(new class extends BaseImportDefinition
            {
                public function key(): string
                {
                    return 'unsafe-unique-column';
                }

                public function fields(): array
                {
                    return [new ImportField('email->value', 'Email')];
                }

                public function uniqueKeyFields(): array
                {
                    return ['email->value'];
                }
            });
            $this->fail('A target lookup key must use a simple column name.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('simple target column names', $exception->getMessage());
        }
    }

    #[Test]
    public function update_upsert_and_skip_modes_require_a_declared_unique_key(): void
    {
        foreach ([ImportMode::Update, ImportMode::Upsert, ImportMode::Skip] as $mode) {
            $definition = new class($mode) extends BaseImportDefinition
            {
                public function __construct(private ImportMode $mode) {}

                public function key(): string
                {
                    return 'key-required-'.$this->mode->value;
                }

                public function writePolicy(): ImportWritePolicy
                {
                    return match ($this->mode) {
                        ImportMode::Update => new ImportWritePolicy($this->mode, missingUpdateTarget: WriteConflictPolicy::Fail),
                        ImportMode::Upsert, ImportMode::Skip => new ImportWritePolicy($this->mode),
                        default => parent::writePolicy(),
                    };
                }
            };

            try {
                (new ImporterRegistry())->register($definition);
                $this->fail(sprintf('%s mode must require a unique matching key.', $mode->value));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('must define unique key fields', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function blank_or_padded_keys_are_rejected(): void
    {
        $registry = new ImporterRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty');

        $registry->register(new class extends BaseImportDefinition
        {
            public function key(): string
            {
                return ' contacts ';
            }
        });
    }

    #[Test]
    public function empty_keys_are_rejected(): void
    {
        $registry = new ImporterRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty');

        $registry->register(new class extends BaseImportDefinition
        {
            public function key(): string
            {
                return '';
            }
        });
    }

    #[Test]
    public function importer_versions_must_be_non_empty_and_trimmed(): void
    {
        $registry = new ImporterRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('versions must be non-empty');

        $registry->register(new class extends BaseImportDefinition
        {
            public function key(): string
            {
                return 'invalid-version';
            }

            public function version(): string
            {
                return ' 1 ';
            }
        });
    }
}

final class ConfiguredImporter extends BaseImportDefinition
{
    public function __construct(public readonly ConfiguredImporterDependency $dependency) {}

    public function label(): string
    {
        return 'Contacts';
    }

    public function key(): string
    {
        return 'contacts';
    }
}

final class ConfiguredImporterDependency {}

final class DuplicateKeyImporter extends BaseImportDefinition
{
    public function key(): string
    {
        return 'contacts';
    }
}
