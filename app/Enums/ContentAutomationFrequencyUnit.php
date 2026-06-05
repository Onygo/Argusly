<?php

namespace App\Enums;

enum ContentAutomationFrequencyUnit: string
{
    case HOURS = 'hours';
    case DAYS = 'days';
    case WEEKS = 'weeks';

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

    public function label(): string
    {
        return match ($this) {
            self::HOURS => 'Hours',
            self::DAYS => 'Days',
            self::WEEKS => 'Weeks',
        };
    }
}
