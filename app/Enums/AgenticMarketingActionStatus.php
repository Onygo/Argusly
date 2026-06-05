<?php

namespace App\Enums;

enum AgenticMarketingActionStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Dismissed = 'dismissed';

    public function isOpen(): bool
    {
        return in_array($this, [self::Proposed, self::Approved, self::Running], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
