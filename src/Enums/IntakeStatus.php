<?php

namespace SmartImport\Enums;

enum IntakeStatus: string
{
    case Discovered = 'discovered';
    case Ready = 'ready';
    case Claimed = 'claimed';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Discovered => [self::Ready, self::Claimed, self::Rejected, self::Archived],
            self::Ready => [self::Claimed, self::Rejected, self::Archived],
            self::Claimed => [self::Ready, self::Rejected, self::Archived],
            self::Rejected => [self::Archived],
            self::Archived => [],
        }, true);
    }
}
