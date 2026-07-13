<?php

namespace App\Enums;

enum BrandGrowthAudienceSourceType: string
{
    case USER_ENTERED = 'user_entered';
    case IMPORTED = 'imported';
    case INFERRED = 'inferred';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
