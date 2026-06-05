<?php

namespace App\Enums;

enum ContentDecayRiskLevel: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Low decay',
            self::MEDIUM => 'Watch closely',
            self::HIGH => 'At risk',
            self::CRITICAL => 'Decaying',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LOW => 'green',
            self::MEDIUM => 'amber',
            self::HIGH => 'orange',
            self::CRITICAL => 'red',
        };
    }
}
