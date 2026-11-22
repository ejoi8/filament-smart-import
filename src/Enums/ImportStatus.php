<?php

namespace SmartImport\Enums;

enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Staging = 'staging';
    case ReviewReady = 'review_ready';
    case Queued = 'queued';
    case Importing = 'importing';
    case Cancelling = 'cancelling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Uploaded => [self::Staging, self::Failed],
            self::Staging => [self::ReviewReady, self::Failed],
            self::ReviewReady => [self::Staging, self::Queued, self::Failed],
            self::Queued => [self::Importing, self::Cancelling, self::Failed],
            self::Importing => [self::Cancelling, self::Completed, self::Failed],
            self::Cancelling => [self::Completed, self::Cancelled, self::Failed],
            self::Completed, self::Cancelled => [self::Queued],
            self::Failed => [self::Queued, self::Staging, self::Completed],
        }, true);
    }
}
