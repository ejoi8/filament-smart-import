<?php

namespace SmartImport\Tests\Unit;

use DomainException;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Enums\ImportStatus;
use SmartImport\Enums\IntakeStatus;
use SmartImport\Models\ImportSession;
use SmartImport\Tests\TestCase;

class ImportStateTransitionTest extends TestCase
{
    #[Test]
    public function an_unassigned_session_can_only_start_as_uploaded(): void
    {
        $session = new ImportSession();
        $session->transitionTo(ImportStatus::Uploaded);
        $session->transitionTo(ImportStatus::Uploaded);

        $this->assertSame(ImportStatus::Uploaded, $session->status);
    }

    #[Test]
    public function an_unregistered_intake_can_only_start_as_discovered(): void
    {
        $session = new ImportSession();
        $session->transitionIntakeTo(IntakeStatus::Discovered);

        $this->assertSame(IntakeStatus::Discovered, $session->intake_status);

        $this->expectException(DomainException::class);

        (new ImportSession())->transitionIntakeTo(IntakeStatus::Ready);
    }

    #[Test]
    public function every_import_status_transition_matches_the_allowed_state_matrix(): void
    {
        $allowed = [
            ImportStatus::Uploaded->value => [ImportStatus::Staging, ImportStatus::Failed],
            ImportStatus::Staging->value => [ImportStatus::ReviewReady, ImportStatus::Failed],
            ImportStatus::ReviewReady->value => [ImportStatus::Staging, ImportStatus::Queued, ImportStatus::Failed],
            ImportStatus::Queued->value => [ImportStatus::Importing, ImportStatus::Cancelling, ImportStatus::Failed],
            ImportStatus::Importing->value => [ImportStatus::Cancelling, ImportStatus::Completed, ImportStatus::Failed],
            ImportStatus::Cancelling->value => [ImportStatus::Completed, ImportStatus::Cancelled, ImportStatus::Failed],
            ImportStatus::Completed->value => [ImportStatus::Queued],
            ImportStatus::Failed->value => [ImportStatus::Queued, ImportStatus::Staging],
            ImportStatus::Cancelled->value => [ImportStatus::Queued],
        ];

        foreach (ImportStatus::cases() as $current) {
            foreach (ImportStatus::cases() as $next) {
                $enumAllows = in_array($next, $allowed[$current->value], true);
                $modelAllows = $current === $next || $enumAllows;
                $session = new ImportSession();
                $session->forceFill(['status' => $current]);

                $this->assertSame(
                    $enumAllows,
                    $current->canTransitionTo($next),
                    "Unexpected enum transition rule: {$current->value} -> {$next->value}.",
                );

                try {
                    $session->transitionTo($next);
                    $transitioned = true;
                } catch (DomainException) {
                    $transitioned = false;
                }

                $this->assertSame(
                    $modelAllows,
                    $transitioned,
                    "Unexpected model transition result: {$current->value} -> {$next->value}.",
                );
                $this->assertSame($modelAllows ? $next : $current, $session->status);
            }
        }
    }

    #[Test]
    public function every_intake_transition_matches_the_allowed_state_matrix(): void
    {
        $allowed = [
            IntakeStatus::Discovered->value => [IntakeStatus::Ready, IntakeStatus::Claimed, IntakeStatus::Rejected, IntakeStatus::Archived],
            IntakeStatus::Ready->value => [IntakeStatus::Claimed, IntakeStatus::Rejected, IntakeStatus::Archived],
            IntakeStatus::Claimed->value => [IntakeStatus::Ready, IntakeStatus::Rejected, IntakeStatus::Archived],
            IntakeStatus::Rejected->value => [IntakeStatus::Archived],
            IntakeStatus::Archived->value => [],
        ];

        foreach (IntakeStatus::cases() as $current) {
            foreach (IntakeStatus::cases() as $next) {
                $enumAllows = in_array($next, $allowed[$current->value], true);
                $modelAllows = $current === $next || $enumAllows;
                $session = new ImportSession();
                $session->forceFill(['intake_status' => $current]);

                $this->assertSame(
                    $enumAllows,
                    $current->canTransitionTo($next),
                    "Unexpected enum transition rule: {$current->value} -> {$next->value}.",
                );

                try {
                    $session->transitionIntakeTo($next);
                    $transitioned = true;
                } catch (DomainException) {
                    $transitioned = false;
                }

                $this->assertSame(
                    $modelAllows,
                    $transitioned,
                    "Unexpected model transition result: {$current->value} -> {$next->value}.",
                );
                $this->assertSame($modelAllows ? $next : $current, $session->intake_status);
            }
        }
    }

    #[Test]
    public function illegal_import_status_transitions_are_rejected(): void
    {
        $session = new ImportSession();

        $this->expectException(DomainException::class);

        $session->transitionTo(ImportStatus::Queued);
    }

    #[Test]
    public function a_review_ready_session_must_be_queued_before_execution(): void
    {
        $session = new ImportSession();
        $session->forceFill(['status' => ImportStatus::ReviewReady]);

        $this->expectException(DomainException::class);

        $session->transitionTo(ImportStatus::Completed);
    }

    #[Test]
    public function a_terminal_import_can_only_restart_as_a_new_queued_attempt(): void
    {
        $session = new ImportSession();
        $session->forceFill(['status' => ImportStatus::Completed]);
        $session->transitionTo(ImportStatus::Queued);

        $this->assertSame(ImportStatus::Queued, $session->status);
    }

    #[Test]
    public function intake_and_import_statuses_have_separate_transitions(): void
    {
        $session = new ImportSession();
        $session->transitionIntakeTo(IntakeStatus::Discovered);
        $session->transitionIntakeTo(IntakeStatus::Claimed);
        $session->transitionIntakeTo(IntakeStatus::Ready);
        $session->transitionTo(ImportStatus::Uploaded);

        $this->assertSame(IntakeStatus::Ready, $session->intake_status);
        $this->assertSame(ImportStatus::Uploaded, $session->status);
    }

    #[Test]
    public function archived_intake_cannot_return_to_ready(): void
    {
        $session = new ImportSession();
        $session->forceFill(['intake_status' => IntakeStatus::Archived]);

        $this->expectException(DomainException::class);

        $session->transitionIntakeTo(IntakeStatus::Ready);
    }
}
