<?php

namespace App\Enums;

enum SocialPlatform: string
{
    case LINKEDIN = 'linkedin';
    case INSTAGRAM = 'instagram';
    case X = 'x';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $platform): string => $platform->value, self::cases());
    }
}
