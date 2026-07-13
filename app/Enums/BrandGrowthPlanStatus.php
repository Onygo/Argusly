<?php

namespace App\Enums;

enum BrandGrowthPlanStatus: string
{
    case DRAFT = 'draft';
    case REVIEWING = 'reviewing';
    case APPROVED = 'approved';
    case SUPERSEDED = 'superseded';
    case ARCHIVED = 'archived';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
