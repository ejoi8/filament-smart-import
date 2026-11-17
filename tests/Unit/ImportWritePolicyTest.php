<?php

namespace SmartImport\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\ImportWriteAction;
use SmartImport\Enums\WriteConflictPolicy;
use SmartImport\Tests\TestCase;

class ImportWritePolicyTest extends TestCase
{
    #[Test]
    public function insert_uses_its_explicit_existing_record_conflict_policy(): void
    {
        $skip = new ImportWritePolicy(ImportMode::Insert, insertConflict: WriteConflictPolicy::Skip);
        $fail = new ImportWritePolicy(ImportMode::Insert, insertConflict: WriteConflictPolicy::Fail);

        $this->assertDecision($skip, 'matched', ImportWriteAction::Skip, 'insert_conflict');
        $this->assertDecision($fail, 'matched', ImportWriteAction::Fail, 'insert_conflict');
        $this->assertDecision($skip, 'new', ImportWriteAction::Create, 'no_existing_record');
        $this->assertDecision($skip, null, ImportWriteAction::Create, 'insert_mode');
    }

    #[Test]
    public function update_requires_a_match_and_uses_its_explicit_missing_target_policy(): void
    {
        $skip = new ImportWritePolicy(ImportMode::Update, missingUpdateTarget: WriteConflictPolicy::Skip);
        $fail = new ImportWritePolicy(ImportMode::Update, missingUpdateTarget: WriteConflictPolicy::Fail);

        $this->assertDecision($skip, 'matched', ImportWriteAction::Update, 'matched_existing_record');
        $this->assertDecision($skip, 'new', ImportWriteAction::Skip, 'update_target_missing');
        $this->assertDecision($fail, 'new', ImportWriteAction::Fail, 'update_target_missing');
        $this->assertDecision($skip, null, ImportWriteAction::Fail, 'update_match_unavailable');
    }

    #[Test]
    public function upsert_creates_new_matches_and_updates_existing_matches(): void
    {
        $policy = new ImportWritePolicy(ImportMode::Upsert);

        $this->assertDecision($policy, 'new', ImportWriteAction::Create, 'no_existing_record');
        $this->assertDecision($policy, 'matched', ImportWriteAction::Update, 'matched_existing_record');
        $this->assertDecision($policy, null, ImportWriteAction::Fail, 'upsert_match_unavailable');
    }

    #[Test]
    public function skip_mode_creates_new_records_and_skips_only_existing_matches(): void
    {
        $policy = new ImportWritePolicy(ImportMode::Skip);

        $this->assertDecision($policy, 'new', ImportWriteAction::Create, 'no_existing_record');
        $this->assertDecision($policy, 'matched', ImportWriteAction::Skip, 'existing_record_skipped');
        $this->assertDecision($policy, null, ImportWriteAction::Fail, 'skip_match_unavailable');
    }

    #[Test]
    public function ambiguous_matches_fail_for_every_mode(): void
    {
        $policies = [
            new ImportWritePolicy(ImportMode::Insert, insertConflict: WriteConflictPolicy::Skip),
            new ImportWritePolicy(ImportMode::Update, missingUpdateTarget: WriteConflictPolicy::Skip),
            new ImportWritePolicy(ImportMode::Upsert),
            new ImportWritePolicy(ImportMode::Skip),
        ];

        foreach ($policies as $policy) {
            $this->assertDecision($policy, 'ambiguous', ImportWriteAction::Fail, 'target_match_ambiguous');
        }
    }

    #[Test]
    public function conflict_policies_must_be_explicit_and_match_the_selected_mode(): void
    {
        try {
            new ImportWritePolicy(ImportMode::Insert);
            $this->fail('Insert mode must declare its conflict policy.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('explicit insert conflict policy', $exception->getMessage());
        }

        try {
            new ImportWritePolicy(ImportMode::Update);
            $this->fail('Update mode must declare its missing-target policy.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('explicit missing-target policy', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        new ImportWritePolicy(ImportMode::Upsert, insertConflict: WriteConflictPolicy::Skip);
    }

    #[Test]
    public function unknown_match_classifications_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ImportWritePolicy(ImportMode::Upsert))->decisionFor('maybe');
    }

    private function assertDecision(
        ImportWritePolicy $policy,
        ?string $changeType,
        ImportWriteAction $expectedAction,
        string $expectedReason,
    ): void {
        $this->assertSame([
            'action' => $expectedAction,
            'reason' => $expectedReason,
        ], $policy->decisionFor($changeType));
    }
}
