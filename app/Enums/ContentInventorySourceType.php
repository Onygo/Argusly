<?php

namespace App\Enums;

enum ContentInventorySourceType: string
{
    case ARGUSLY_MANAGED = 'argusly_managed';
    case OBSERVED_ANALYTICS = 'observed_analytics';
    case MANUAL_EXTERNAL_URL = 'manual_external_url';
    case CMS_CONNECTED = 'cms_connected';
    case SITEMAP_DISCOVERED = 'sitemap_discovered';
    case CONNECTOR_IMPORT = 'connector_import';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
