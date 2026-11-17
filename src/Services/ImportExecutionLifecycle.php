<?php

namespace SmartImport\Services;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SmartImport\Enums\ImportStatus;
use SmartImport\Events\ImportFinished;
use SmartImport\Models\ImportSession;
use Throwable;

final class ImportExecutionLifecycle
{
    public function __construct(
        private ImporterRegistry $importers,
        private ImportHookDispatcher $hooks,
        private ImportAuthorization $authorization,
    ) {}

    /** Request cancellation through the actor-scoped session boundary. */
    public function requestCancellation(string $sessionId, Authenticatable $actor): ImportSession
    {
        return DB::transaction(function () use ($sessionId, $actor): ImportSession {
            $session = $this->authorization
                ->applyActorScope(ImportSession::query()->lockForUpdate(), $actor)
                ->whereKey($sessionId)
                ->firstOrFail();

            $this->authorization->authorize('view', $actor, $session);
            $this->authorization->authorize('cancel', $actor, $session);

            if ($session->status === ImportStatus::Cancelling) {
                return $session;
            }

            if (! in_array($session->status, [ImportStatus::Queued, ImportStatus::Importing], true)) {
                throw new DomainException('Only queued or importing sessions can be cancelled.');
            }

            if ($session->processed > $session->execution_total) {
                throw new DomainException('The execution counter exceeded its confirmed row total.');
            }

            $session->transitionTo(ImportStatus::Cancelling);
            $now = now();
            $session->forceFill([
                'cancel_requested_at' => $session->cancel_requested_at ?? $now,
                'heartbeat_at' => $now,
            ]);

            if ($session->status === ImportStatus::Cancelling
                && ($session->processed === $session->execution_total || $session->started_at === null)) {
                $terminal = $session->processed === $session->execution_total
                    ? ImportStatus::Completed
                    : ImportStatus::Cancelled;
                $session->transitionTo($terminal);
                $session->forceFill(['finished_at' => $now]);
            }

            if (in_array($session->status, [ImportStatus::Completed, ImportStatus::Cancelled], true)) {
                $this->recordAttemptOutcome($session);
            }

            $session->save();

            if (in_array($session->status, [ImportStatus::Completed, ImportStatus::Cancelled], true)) {
                $this->dispatchTerminalCallbacksAfterCommit($session);
            }

            return $session;
        }, 3);
    }

    /** Move one queued attempt to importing; only the first chunk runs beforeImport. */
    public function beginChunk(string $sessionId, int $attemptId, int $confirmedRevision): bool
    {
        return DB::transaction(function () use ($sessionId, $attemptId, $confirmedRevision): bool {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if (! $this->matchesAttempt($session, $attemptId, $confirmedRevision)) {
                return false;
            }

            if ($session->status === ImportStatus::Cancelling) {
                $this->finishCancellation($session);

                return false;
            }

            if (! in_array($session->status, [ImportStatus::Queued, ImportStatus::Importing], true)) {
                return false;
            }

            if ($session->status === ImportStatus::Queued) {
                if ($session->started_at === null) {
                    $definition = $this->importers->get((string) $session->importer_key);

                    if ($definition->version() !== $session->importer_version) {
                        throw new DomainException('The importer definition changed after the execution was queued.');
                    }

                    $this->hooks->beforeImport($definition, $session);
                }

                $session->transitionTo(ImportStatus::Importing);
                $session->forceFill([
                    'started_at' => $session->started_at ?? now(),
                    'heartbeat_at' => now(),
                ]);
                $session->save();
            } else {
                $session->forceFill(['heartbeat_at' => now()]);
                $session->save();
            }

            return true;
        }, 3);
    }

    /** Complete all committed rows or finalize a cooperative cancellation at a safe boundary. */
    public function completeIfReady(string $sessionId, int $attemptId, int $confirmedRevision): bool
    {
        return DB::transaction(function () use ($sessionId, $attemptId, $confirmedRevision): bool {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if (! $this->matchesAttempt($session, $attemptId, $confirmedRevision)) {
                return false;
            }

            if ($session->status === ImportStatus::Cancelling) {
                $this->finishCancellation($session);

                return true;
            }

            if (! in_array($session->status, [ImportStatus::Queued, ImportStatus::Importing], true)) {
                return false;
            }

            if ($session->processed < $session->execution_total) {
                return false;
            }

            if ($session->processed > $session->execution_total) {
                throw new DomainException('The execution counter exceeded its confirmed row total.');
            }

            $session->transitionTo(ImportStatus::Completed);
            $session->forceFill([
                'finished_at' => now(),
                'heartbeat_at' => now(),
            ]);
            $this->recordAttemptOutcome($session);
            $session->save();
            $this->dispatchTerminalCallbacksAfterCommit($session);

            return true;
        }, 3);
    }

    /** Fail a queued or partially importing session when its work cannot be dispatched. */
    public function failDispatch(string $sessionId, int $attemptId): void
    {
        DB::transaction(function () use ($sessionId, $attemptId): void {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if ($session === null
                || $session->current_attempt !== $attemptId
                || ! in_array($session->status, [ImportStatus::Queued, ImportStatus::Importing], true)) {
                return;
            }

            $terminalStatus = $session->processed === $session->execution_total
                ? ImportStatus::Completed
                : ImportStatus::Failed;
            $session->transitionTo($terminalStatus);
            $session->forceFill([
                'error_summary' => $terminalStatus === ImportStatus::Failed
                    ? 'Execution jobs could not be dispatched. Check the queue connection and worker configuration.'
                    : null,
                'heartbeat_at' => now(),
                'finished_at' => now(),
            ]);
            $this->recordAttemptOutcome($session);
            $session->save();
            $this->dispatchTerminalCallbacksAfterCommit($session);
        }, 3);
    }

    /** Persist a safe failure when recovery finds an invariant violation or exhausted recovery limit. */
    public function failRecovery(string $sessionId, int $attemptId, int $confirmedRevision, string $safeMessage): void
    {
        DB::transaction(function () use ($sessionId, $attemptId, $confirmedRevision, $safeMessage): void {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if (! $this->matchesAttempt($session, $attemptId, $confirmedRevision)) {
                return;
            }

            if ($session->status === ImportStatus::Cancelling) {
                $this->finishCancellation($session);

                return;
            }

            if (! in_array($session->status, [ImportStatus::Queued, ImportStatus::Importing, ImportStatus::Failed], true)) {
                return;
            }

            $wasAlreadyFailed = $session->status === ImportStatus::Failed;

            if (! $wasAlreadyFailed) {
                $session->transitionTo(ImportStatus::Failed);
            }

            $session->forceFill([
                'error_summary' => $safeMessage,
                'heartbeat_at' => now(),
                'finished_at' => now(),
            ]);
            $this->recordAttemptOutcome($session);
            $session->save();

            if (! $wasAlreadyFailed) {
                $this->dispatchTerminalCallbacksAfterCommit($session);
            }
        }, 3);
    }

    /** Mark a chunk attempt failed only after Laravel exhausts its configured retries. */
    public function failChunk(string $sessionId, int $attemptId, int $confirmedRevision): void
    {
        DB::transaction(function () use ($sessionId, $attemptId, $confirmedRevision): void {
            $session = ImportSession::query()->lockForUpdate()->find($sessionId);

            if (! $this->matchesAttempt($session, $attemptId, $confirmedRevision)) {
                return;
            }

            if ($session->status === ImportStatus::Cancelling) {
                $this->finishCancellation($session);

                return;
            }

            if (! in_array($session->status, [ImportStatus::Queued, ImportStatus::Importing], true)) {
                return;
            }

            if ($session->processed > $session->execution_total) {
                throw new DomainException('The execution counter exceeded its confirmed row total.');
            }

            $terminalStatus = $session->processed === $session->execution_total
                ? ImportStatus::Completed
                : ImportStatus::Failed;
            $session->transitionTo($terminalStatus);
            $now = now();
            $session->forceFill([
                'error_summary' => $terminalStatus === ImportStatus::Failed
                    ? 'An execution chunk exhausted its configured queue retries. Run smart-import:recover after checking queue worker health.'
                    : null,
                'heartbeat_at' => $now,
                'finished_at' => $now,
            ]);
            $this->recordAttemptOutcome($session);
            $session->save();
            $this->dispatchTerminalCallbacksAfterCommit($session);
        }, 3);
    }

    private function matchesAttempt(?ImportSession $session, int $attemptId, int $confirmedRevision): bool
    {
        return $session !== null
            && $session->current_attempt === $attemptId
            && $session->confirmed_revision === $confirmedRevision
            && $session->staging_revision === $confirmedRevision;
    }

    private function finishCancellation(ImportSession $session): void
    {
        if ($session->processed > $session->execution_total) {
            throw new DomainException('The execution counter exceeded its confirmed row total.');
        }

        $session->transitionTo($session->processed === $session->execution_total
            ? ImportStatus::Completed
            : ImportStatus::Cancelled);
        $now = now();
        $session->forceFill([
            'finished_at' => $now,
            'heartbeat_at' => $now,
        ]);
        $this->recordAttemptOutcome($session);
        $session->save();
        $this->dispatchTerminalCallbacksAfterCommit($session);
    }

    private function recordAttemptOutcome(ImportSession $session): void
    {
        $history = $session->attempt_history ?? [];
        $attemptIndex = null;

        for ($index = count($history) - 1; $index >= 0; $index--) {
            if (($history[$index]['attempt'] ?? null) === $session->current_attempt) {
                $attemptIndex = $index;

                break;
            }
        }

        $summary = [
            'attempt' => $session->current_attempt,
            'confirmed_revision' => $session->confirmed_revision,
            'execution_total' => $session->execution_total,
            'status' => $session->status?->value,
            'processed' => $session->processed,
            'created_count' => $session->created_count,
            'updated_count' => $session->updated_count,
            'skipped_count' => $session->skipped_count,
            'failed_count' => $session->failed_count,
            'finished_at' => $session->finished_at?->toISOString() ?? now()->toISOString(),
        ];

        if ($attemptIndex === null) {
            $history[] = $summary;
        } else {
            $history[$attemptIndex] = array_merge($history[$attemptIndex], $summary);
        }

        $session->forceFill(['attempt_history' => $history]);
    }

    private function dispatchTerminalCallbacksAfterCommit(ImportSession $session): void
    {
        $sessionId = (string) $session->getKey();
        $attemptId = $session->current_attempt;
        $status = $session->status;
        $snapshot = new ImportFinished(
            $sessionId,
            $attemptId,
            $status,
            $session->processed,
            $session->created_count,
            $session->updated_count,
            $session->skipped_count,
            $session->failed_count,
        );

        $session->getConnection()->afterCommit(function () use ($sessionId, $snapshot): void {
            try {
                $fresh = ImportSession::query()->find($sessionId);

                if ($fresh !== null) {
                    $definition = $this->importers->get((string) $fresh->importer_key);
                    $this->hooks->afterImport($definition, $fresh);
                }
            } catch (Throwable $exception) {
                Log::error('Smart Import after-import hook failed after terminal state was committed.', [
                    'import_id' => $sessionId,
                    'attempt' => $snapshot->attemptId,
                    'exception' => $exception::class,
                ]);
            }

            try {
                event($snapshot);
            } catch (Throwable $exception) {
                Log::error('Smart Import terminal event listener failed after state was committed.', [
                    'import_id' => $sessionId,
                    'attempt' => $snapshot->attemptId,
                    'exception' => $exception::class,
                ]);
            }
        });
    }
}
