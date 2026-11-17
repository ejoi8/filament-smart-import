<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Data\RelationshipLookup;
use SmartImport\Enums\ImportStatus;
use SmartImport\Jobs\StageImportJob;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\ImportCoordinator;
use SmartImport\Services\ImportStagingService;
use SmartImport\Services\ImporterRegistry;
use SmartImport\Services\RowDataProcessor;
use SmartImport\Tests\Fixtures\RelationshipCustomer;
use SmartImport\Tests\Fixtures\RelationshipImportDefinition;
use SmartImport\Tests\TestCase;

class RelationshipLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('relationship_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Storage::fake('local');
        Queue::fake();
        Gate::define('create', fn (): bool => true);
        Gate::define('view', fn (): bool => true);
        Gate::define('update', fn (): bool => true);
        Gate::define('useImporter', fn (): bool => true);
    }

    #[Test]
    public function a_belongs_to_lookup_resolves_only_one_related_owner_key(): void
    {
        $customer = RelationshipCustomer::query()->create(['email' => 'ada@example.test', 'active' => true]);
        $definition = new RelationshipImportDefinition();
        $result = app(RowDataProcessor::class)->process(
            $definition,
            [1 => 'ada@example.test'],
            [1 => 'customer_id'],
            2,
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('ada@example.test', $result['mapped_values']['customer_id']);
        $this->assertSame($customer->getKey(), $result['normalized_values']['customer_id']);
        $this->assertSame([], $result['errors']);

        $idLookup = app(RowDataProcessor::class)->process(
            new RelationshipImportDefinition(new RelationshipLookup('customer', 'id')),
            [1 => (string) $customer->getKey()],
            [1 => 'customer_id'],
            3,
        );
        $this->assertTrue($idLookup['valid']);
        $this->assertSame($customer->getKey(), $idLookup['normalized_values']['customer_id']);
    }

    #[Test]
    public function missing_and_ambiguous_relationship_values_return_safe_row_errors(): void
    {
        RelationshipCustomer::query()->create(['email' => 'duplicate@example.test', 'active' => true]);
        RelationshipCustomer::query()->create(['email' => 'duplicate@example.test', 'active' => true]);
        $processor = app(RowDataProcessor::class);
        $definition = new RelationshipImportDefinition();

        $missing = $processor->process($definition, [1 => 'secret@example.test'], [1 => 'customer_id'], 11);
        $ambiguous = $processor->process($definition, [1 => 'duplicate@example.test'], [1 => 'customer_id'], 12);

        $this->assertFalse($missing['valid']);
        $this->assertSame('relationship_not_found', $missing['errors'][0]['code']);
        $this->assertSame(11, $missing['errors'][0]['source_row']);
        $this->assertSame('No related record matches this value.', $missing['errors'][0]['safe_message']);
        $this->assertArrayNotHasKey('customer_id', $missing['normalized_values']);
        $this->assertStringNotContainsString('secret@example.test', $missing['errors'][0]['safe_message']);

        $this->assertFalse($ambiguous['valid']);
        $this->assertSame('relationship_ambiguous', $ambiguous['errors'][0]['code']);
        $this->assertSame(12, $ambiguous['errors'][0]['source_row']);
        $this->assertArrayNotHasKey('customer_id', $ambiguous['normalized_values']);
    }

    #[Test]
    public function inactive_related_records_are_not_resolved_through_the_relationship_constraint(): void
    {
        RelationshipCustomer::query()->create(['email' => 'inactive@example.test', 'active' => false]);
        $result = app(RowDataProcessor::class)->process(
            new RelationshipImportDefinition(),
            [1 => 'inactive@example.test'],
            [1 => 'customer_id'],
            4,
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('relationship_not_found', $result['errors'][0]['code']);
    }

    #[Test]
    public function registry_rejects_unregistered_relationship_methods_and_mismatched_foreign_keys(): void
    {
        try {
            new ImporterRegistry([
                new RelationshipImportDefinition(new RelationshipLookup('missing', 'email')),
            ]);
            $this->fail('A relationship lookup must name a relationship method on the target model.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('public belongs-to method', $exception->getMessage());
        }

        try {
            new ImporterRegistry([
                new RelationshipImportDefinition(fieldName: 'other_id'),
            ]);
            $this->fail('A relationship lookup field must be the relation foreign key.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('must match the [customer] foreign key', $exception->getMessage());
        }

        try {
            new ImporterRegistry([
                new RelationshipImportDefinition(new RelationshipLookup('customers', 'email')),
            ]);
            $this->fail('A lookup must use a belongs-to relation rather than a collection relation.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('must use an Eloquent belongs-to relationship', $exception->getMessage());
        }
    }

    #[Test]
    public function lookup_identifiers_must_not_contain_sql_or_path_syntax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RelationshipLookup('customer', 'email;drop table customers');
    }

    #[Test]
    public function staging_persists_relationship_resolution_errors_and_only_resolved_keys(): void
    {
        config()->set('smart-import.importers', [RelationshipImportDefinition::class]);
        $customer = RelationshipCustomer::query()->create(['email' => 'ada@example.test', 'active' => true]);
        RelationshipCustomer::query()->create(['email' => 'duplicate@example.test', 'active' => true]);
        RelationshipCustomer::query()->create(['email' => 'duplicate@example.test', 'active' => true]);
        $actor = new GenericUser(['id' => 7]);
        $session = app(FileIntakeService::class)->upload(
            UploadedFile::fake()->createWithContent(
                'customers.csv',
                "Customer email\nada@example.test\nduplicate@example.test\nmissing@example.test\n",
            ),
            $actor,
        );
        app(ImportCoordinator::class)->assignImporter((string) $session->getKey(), 'relationship-import', $actor);
        $staging = app(ImportStagingService::class)->start(
            (string) $session->getKey(),
            $actor,
            'relationship-import',
            0,
            1,
            [1 => 'customer_id'],
        );

        $this->assertSame(ImportStatus::Staging, $staging->status);
        $relationshipQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$relationshipQueries): void {
            if (str_contains($query->sql, 'relationship_customers')) {
                $relationshipQueries++;
            }
        });
        (new StageImportJob((string) $staging->getKey(), $staging->mapping_revision, $staging->staging_revision))
            ->handle(app(ImportStagingService::class));

        $rows = $staging->fresh()->rows()->orderBy('source_row_number')->get();
        $this->assertSame(1, $relationshipQueries);
        $this->assertSame(1, $staging->fresh()->stage_valid);
        $this->assertSame(2, $staging->fresh()->stage_invalid);
        $this->assertSame($customer->getKey(), $rows[0]->normalized_values['customer_id']);
        $this->assertSame('ada@example.test', $rows[0]->mapped_values['customer_id']);
        $this->assertSame('relationship_ambiguous', $rows[1]->validation_errors[0]['code']);
        $this->assertSame('relationship_not_found', $rows[2]->validation_errors[0]['code']);
        $this->assertArrayNotHasKey('customer_id', $rows[1]->normalized_values);
        $this->assertArrayNotHasKey('customer_id', $rows[2]->normalized_values);
    }
}
