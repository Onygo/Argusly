<?php

namespace App\Enums;

enum AccessOverrideType: string
{
    case EARLY_ACCESS = 'early_access';
    case TRIAL_OVERRIDE = 'trial_override';

    public function label(): string
    {
        return match ($this) {
            self::EARLY_ACCESS => 'Early access',
            self::TRIAL_OVERRIDE => 'Trial override',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
