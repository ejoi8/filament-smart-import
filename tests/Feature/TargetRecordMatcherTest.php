<?php

namespace SmartImport\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Services\TargetRecordMatcher;
use SmartImport\Services\StagedRowService;
use SmartImport\Tests\Fixtures\CompositeStagingImportDefinition;
use SmartImport\Tests\Fixtures\ImportTargetModel;
use SmartImport\Tests\Fixtures\StagingImportDefinition;
use SmartImport\Tests\TestCase;

class TargetRecordMatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('import_target_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('department')->nullable();
            $table->timestamps();
        });
    }

    #[Test]
    public function complete_keys_are_classified_as_new_or_matched_and_incomplete_keys_are_unresolved(): void
    {
        $existing = $this->target(['name' => 'Ada', 'email' => 'ada@example.test']);
        $matches = app(TargetRecordMatcher::class)->matchBatch(new StagingImportDefinition(), [
            ['name' => 'Changed name', 'email' => 'ada@example.test'],
            ['name' => 'Grace', 'email' => 'grace@example.test'],
            ['name' => 'Missing email'],
        ]);

        $this->assertSame('matched', $matches[0]['change_type']);
        $this->assertSame(ImportTargetModel::class, $matches[0]['target_type']);
        $this->assertSame((string) $existing->getKey(), $matches[0]['target_id']);
        $this->assertSame('new', $matches[1]['change_type']);
        $this->assertNull($matches[2]['change_type']);
        $this->assertSame([], $matches[0]['errors']);
    }

    #[Test]
    public function multiple_target_records_for_one_key_are_reported_as_ambiguous(): void
    {
        $this->target(['name' => 'Ada One', 'email' => 'shared@example.test']);
        $this->target(['name' => 'Ada Two', 'email' => 'shared@example.test']);
        $matches = app(TargetRecordMatcher::class)->matchBatch(new StagingImportDefinition(), [
            ['name' => 'Ada', 'email' => 'shared@example.test'],
        ]);

        $this->assertSame('ambiguous', $matches[0]['change_type']);
        $this->assertNull($matches[0]['target_id']);
        $this->assertSame(['email'], array_column($matches[0]['errors'], 'field'));
        $this->assertSame(['target_match_ambiguous'], array_column($matches[0]['errors'], 'code'));
        $this->assertSame('This key matches more than one existing target record.', $matches[0]['errors'][0]['safe_message']);
    }

    #[Test]
    public function composite_target_keys_match_only_when_every_component_matches(): void
    {
        $existing = $this->target(['name' => 'Ada', 'department' => 'Engineering']);
        $matches = app(TargetRecordMatcher::class)->matchBatch(new CompositeStagingImportDefinition(), [
            ['name' => 'Ada', 'department' => 'Engineering'],
            ['name' => 'Ada', 'department' => 'Finance'],
        ]);

        $this->assertSame('matched', $matches[0]['change_type']);
        $this->assertSame((string) $existing->getKey(), $matches[0]['target_id']);
        $this->assertSame('new', $matches[1]['change_type']);
    }

    #[Test]
    public function key_hashes_use_the_same_scalar_equivalence_as_target_matching(): void
    {
        $definition = new StagingImportDefinition();
        $matcher = app(TargetRecordMatcher::class);
        $target = $this->target(['name' => 'One', 'email' => '1']);
        $rows = $matcher->matchBatch($definition, [
            ['name' => 'Integer key', 'email' => 1],
            ['name' => 'String key', 'email' => '1'],
        ]);

        $intHash = app(StagedRowService::class)->matchKeyHash($definition, ['name' => 'Integer key', 'email' => 1]);
        $stringHash = app(StagedRowService::class)->matchKeyHash($definition, ['name' => 'String key', 'email' => '1']);

        $this->assertSame('matched', $rows[0]['change_type']);
        $this->assertSame((string) $target->getKey(), $rows[0]['target_id']);
        $this->assertSame($rows[0]['target_id'], $rows[1]['target_id']);
        $this->assertSame($intHash, $stringHash);
    }

    #[Test]
    public function target_matching_uses_one_query_for_a_bounded_batch(): void
    {
        $this->target(['name' => 'Ada', 'email' => 'ada@example.test']);
        $this->target(['name' => 'Grace', 'email' => 'grace@example.test']);
        $targetQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$targetQueries): void {
            if (str_contains($query->sql, 'import_target_models')) {
                $targetQueries++;
            }
        });

        $matches = app(TargetRecordMatcher::class)->matchBatch(new StagingImportDefinition(), [
            ['name' => 'Ada', 'email' => 'ada@example.test'],
            ['name' => 'Grace', 'email' => 'grace@example.test'],
            ['name' => 'New', 'email' => 'new@example.test'],
        ]);

        $this->assertCount(3, $matches);
        $this->assertSame(1, $targetQueries);
    }

    /** @param array<string, mixed> $attributes */
    private function target(array $attributes): ImportTargetModel
    {
        $target = new ImportTargetModel();
        $target->forceFill($attributes);
        $target->save();

        return $target;
    }
}
