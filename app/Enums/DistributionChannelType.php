<?php

namespace App\Enums;

enum DistributionChannelType: string
{
    case WEBSITE = 'website';
    case WORDPRESS = 'wordpress';
    case LARAVEL = 'laravel';
    case LINKEDIN = 'linkedin';
    case X = 'x';
    case NEWSLETTER = 'newsletter';
    case API = 'api';
    case MANUAL = 'manual';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
