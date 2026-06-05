<?php

namespace App\Enums;

enum ContentAutomationTriggerType: string
{
    case SCHEDULED = 'scheduled';
    case MANUAL = 'manual';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
