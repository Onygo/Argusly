<?php

namespace App\Enums;

enum ContentManagementType: string
{
    case MANAGED = 'managed';
    case OBSERVED = 'observed';
    case EXTERNAL_REFERENCE = 'external_reference';
    case CMS_MANAGED = 'cms_managed';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
