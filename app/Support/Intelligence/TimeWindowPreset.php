<?php

namespace App\Support\Intelligence;

enum TimeWindowPreset: string
{
    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case LAST_7_DAYS = 'last_7_days';
    case LAST_28_DAYS = 'last_28_days';
    case ROLLING = 'rolling';
    case CAMPAIGN_WINDOW = 'campaign_window';
    case RELEASE_WINDOW = 'release_window';
    case CUSTOM_RANGE = 'custom_range';

    public static function normalize(self|string|null $preset, ?self $default = null): self
    {
        if ($preset instanceof self) {
            return $preset;
        }

        $value = str($preset ?? '')
            ->lower()
            ->trim()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        return match ($value) {
            'today' => self::TODAY,
            'yesterday' => self::YESTERDAY,
            '7d', 'last_7', 'last_7_day', 'last_7_days' => self::LAST_7_DAYS,
            '28d', 'last_28', 'last_28_day', 'last_28_days' => self::LAST_28_DAYS,
            'rolling', 'rolling_window', 'rolling_range' => self::ROLLING,
            'campaign', 'campaign_range', 'campaign_window' => self::CAMPAIGN_WINDOW,
            'release', 'launch', 'release_range', 'release_window', 'launch_window' => self::RELEASE_WINDOW,
            'custom', 'range', 'date_range', 'custom_range' => self::CUSTOM_RANGE,
            default => $default ?? self::CUSTOM_RANGE,
        };
    }
}
