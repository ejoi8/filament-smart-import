<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use SmartImport\Data\ImportActorIdentity;
use SmartImport\Models\ImportAuditEntry;
use SmartImport\Models\ImportSession;

final class ImportAuditService
{
    private const EVENT_FIELDS = [
        'intake.registered' => ['source', 'file_type'],
        'intake.rejected' => ['source', 'file_type'],
        'intake.archived' => [],
        'intake.claimed' => [],
        'importer.assigned' => ['importer_key'],
        'configuration.saved' => ['importer_key', 'mapping_revision', 'staging_revision', 'sheet_index', 'header_row', 'source_column_count', 'mapped_field_count', 'settings_changed'],
        'staging.completed' => ['staging_revision', 'stage_total', 'valid', 'invalid', 'warnings'],
        'staging.failed' => ['staging_revision', 'recovery_count'],
        'row.corrected' => ['row_number', 'staging_revision', 'field_count'],
        'preview.generated' => ['staging_revision', 'total', 'create', 'update', 'skip', 'fail'],
        'import.confirmed' => ['confirmed_revision', 'attempt_id', 'execution_total', 'excluded_count'],
        'import.cancellation_requested' => ['attempt_id', 'processed'],
        'import.completed' => ['status', 'attempt_id', 'processed', 'created', 'updated', 'skipped', 'failed'],
        'import.failed' => ['status', 'attempt_id', 'processed', 'created', 'updated', 'skipped', 'failed'],
        'import.cancelled' => ['status', 'attempt_id', 'processed', 'created', 'updated', 'skipped', 'failed'],
        'import.retry_started' => ['attempt_id', 'retry_of_attempt', 'execution_total'],
        'import.recovery_requeued' => ['attempt_id', 'recovery_count', 'stage'],
        'import.recovery_failed' => ['attempt_id', 'recovery_count'],
        'notification.delivery_failed' => ['outbox_id', 'delivery_attempt'],
        'retention.hold_set' => ['hold_active'],
        'retention.hold_released' => ['hold_active'],
    ];

    private const STRING_FIELDS = ['source', 'file_type', 'importer_key', 'status', 'stage'];

    private const INTEGER_FIELDS = [
        'mapping_revision', 'staging_revision', 'attempt_id', 'sheet_index', 'header_row',
        'source_column_count', 'mapped_field_count', 'stage_total', 'valid', 'invalid',
        'warnings', 'row_number', 'field_count', 'total', 'create', 'update', 'skip',
        'fail', 'confirmed_revision', 'execution_total', 'excluded_count', 'processed',
        'created', 'updated', 'skipped', 'failed', 'retry_of_attempt', 'recovery_count',
        'outbox_id', 'delivery_attempt',
    ];

    private const BOOLEAN_FIELDS = ['settings_changed', 'hold_active'];

    public function __construct(private ImportAuthorization $authorization) {}

    public function record(
        ImportSession $session,
        string $event,
        ?Authenticatable $actor = null,
        ?string $reasonCode = null,
        array $metadata = [],
    ): ImportAuditEntry {
        if (! isset(self::EVENT_FIELDS[$event])) {
            throw new InvalidArgumentException('The Smart Import audit event is not supported.');
        }

        $connection = $session->getConnection();

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Smart Import audit entries must be recorded in the operation transaction.');
        }

        if ($reasonCode !== null && ! preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $reasonCode)) {
            throw new InvalidArgumentException('Smart Import audit reason codes must be short lowercase identifiers.');
        }

        $safeMetadata = $this->normalizeMetadata($event, $metadata);
        $encoded = json_encode($safeMetadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (strlen($encoded) > 2048) {
            throw new InvalidArgumentException('Smart Import audit metadata cannot exceed 2048 encoded bytes.');
        }

        $identity = $actor === null ? null : ImportActorIdentity::from($actor);
        $entry = new ImportAuditEntry();
        $entry->setConnection($session->getConnectionName());
        $entry->forceFill([
            'import_id' => (string) $session->getKey(),
            'tenant_id' => $session->tenant_id,
            'actor_type' => $identity?->type,
            'actor_id' => $identity?->id,
            'event' => $event,
            'reason_code' => $reasonCode,
            'mapping_revision' => $session->mapping_revision,
            'staging_revision' => $session->staging_revision,
            'attempt_id' => $session->current_attempt > 0 ? $session->current_attempt : null,
            'metadata' => $safeMetadata === [] ? null : $safeMetadata,
        ]);
        $entry->save();

        return $entry;
    }

    /** @return Collection<int, ImportAuditEntry> */
    public function latestFor(string $sessionId, Authenticatable $actor, int $limit = 100): Collection
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Smart Import audit page limits must be between 1 and 200.');
        }

        $session = $this->authorization
            ->applyActorScope(ImportSession::query(), $actor)
            ->whereKey($sessionId)
            ->firstOrFail();
        $this->authorization->authorize('view', $actor, $session);

        return ImportAuditEntry::query()
            ->where('import_id', $session->getKey())
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @param array<string, mixed> $metadata @return array<string, int|string|bool> */
    private function normalizeMetadata(string $event, array $metadata): array
    {
        if (array_diff(array_keys($metadata), self::EVENT_FIELDS[$event]) !== []) {
            throw new InvalidArgumentException('Smart Import audit metadata contains an unsupported field.');
        }

        $normalized = [];

        foreach ($metadata as $key => $value) {
            if (in_array($key, self::INTEGER_FIELDS, true)) {
                if (! is_int($value) || $value < 0 || $value > 4294967295) {
                    throw new InvalidArgumentException('Smart Import audit counts and revisions must be bounded non-negative integers.');
                }

                $normalized[$key] = $value;

                continue;
            }

            if (in_array($key, self::BOOLEAN_FIELDS, true)) {
                if (! is_bool($value)) {
                    throw new InvalidArgumentException('Smart Import audit flags must be boolean.');
                }

                $normalized[$key] = $value;

                continue;
            }

            if (! in_array($key, self::STRING_FIELDS, true) || ! is_string($value) || strlen($value) > 100) {
                throw new InvalidArgumentException('Smart Import audit metadata must contain only bounded safe values.');
            }

            $valid = match ($key) {
                'source' => in_array($value, ['web', 'sftp', 'cli'], true),
                'status' => in_array($value, ['uploaded', 'staging', 'review_ready', 'queued', 'importing', 'cancelling', 'completed', 'failed', 'cancelled'], true),
                'stage' => in_array($value, ['staging', 'execution'], true),
                'file_type' => preg_match('/\A[a-z0-9.+-]{1,100}\z/i', $value) === 1,
                'importer_key' => preg_match('/\A[a-z0-9][a-z0-9._-]{0,99}\z/i', $value) === 1,
                default => false,
            };

            if (! $valid) {
                throw new InvalidArgumentException('Smart Import audit metadata contains an unsupported value.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
