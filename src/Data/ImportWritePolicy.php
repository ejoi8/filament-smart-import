<?php

namespace SmartImport\Data;

use InvalidArgumentException;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\ImportWriteAction;
use SmartImport\Enums\WriteConflictPolicy;

final readonly class ImportWritePolicy
{
    public function __construct(
        public ImportMode $mode,
        public ?WriteConflictPolicy $insertConflict = null,
        public ?WriteConflictPolicy $missingUpdateTarget = null,
        public bool $allowInvalidRowExclusion = false,
    ) {
        if (($mode === ImportMode::Insert) !== ($insertConflict !== null)) {
            throw new InvalidArgumentException('Insert mode requires an explicit insert conflict policy, and other modes cannot set one.');
        }

        if (($mode === ImportMode::Update) !== ($missingUpdateTarget !== null)) {
            throw new InvalidArgumentException('Update mode requires an explicit missing-target policy, and other modes cannot set one.');
        }
    }

    /**
     * Resolve an existing-record classification into the proposed row outcome.
     *
     * @return array{action: ImportWriteAction, reason: string}
     */
    public function decisionFor(?string $changeType): array
    {
        if (! in_array($changeType, [null, 'new', 'matched', 'ambiguous'], true)) {
            throw new InvalidArgumentException('The target match classification is not supported.');
        }

        if ($changeType === 'ambiguous') {
            return ['action' => ImportWriteAction::Fail, 'reason' => 'target_match_ambiguous'];
        }

        return match ($this->mode) {
            ImportMode::Insert => $changeType === 'matched'
                ? $this->conflictDecision($this->insertConflict, 'insert_conflict')
                : ['action' => ImportWriteAction::Create, 'reason' => $changeType === 'new' ? 'no_existing_record' : 'insert_mode'],
            ImportMode::Update => match ($changeType) {
                'matched' => ['action' => ImportWriteAction::Update, 'reason' => 'matched_existing_record'],
                'new' => $this->conflictDecision($this->missingUpdateTarget, 'update_target_missing'),
                default => ['action' => ImportWriteAction::Fail, 'reason' => 'update_match_unavailable'],
            },
            ImportMode::Upsert => match ($changeType) {
                'matched' => ['action' => ImportWriteAction::Update, 'reason' => 'matched_existing_record'],
                'new' => ['action' => ImportWriteAction::Create, 'reason' => 'no_existing_record'],
                default => ['action' => ImportWriteAction::Fail, 'reason' => 'upsert_match_unavailable'],
            },
            ImportMode::Skip => match ($changeType) {
                'matched' => ['action' => ImportWriteAction::Skip, 'reason' => 'existing_record_skipped'],
                'new' => ['action' => ImportWriteAction::Create, 'reason' => 'no_existing_record'],
                default => ['action' => ImportWriteAction::Fail, 'reason' => 'skip_match_unavailable'],
            },
        };
    }

    /** @return array{action: ImportWriteAction, reason: string} */
    private function conflictDecision(?WriteConflictPolicy $policy, string $reason): array
    {
        return [
            'action' => $policy === WriteConflictPolicy::Skip ? ImportWriteAction::Skip : ImportWriteAction::Fail,
            'reason' => $reason,
        ];
    }
}
