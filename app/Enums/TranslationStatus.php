<?php

namespace App\Enums;

enum TranslationStatus: string
{
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case TRANSLATING = 'translating';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::QUEUED => 'Queued',
            self::TRANSLATING => 'Translating',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }

    public function isInProgress(): bool
    {
        return in_array($this, [self::QUEUED, self::TRANSLATING], true);
    }

    public function canRetry(): bool
    {
        return $this === self::FAILED;
    }
}
