<?php

namespace App\Enums;

enum AgenticMarketingOpportunityStatus: string
{
    case Open = 'open';
    case Dismissed = 'dismissed';
    case Completed = 'completed';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
