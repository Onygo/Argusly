<?php

namespace App\Enums;

enum SignalSeverity: string
{
    case INFO = 'info';
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    public static function values(): array
    {
        return array_map(static fn (self $severity): string => $severity->value, self::cases());
    }
}
