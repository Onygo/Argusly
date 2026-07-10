<?php

namespace App\Enums;

enum ContentDiscoveryMethod: string
{
    case ARGUSLY_CREATED = 'argusly_created';
    case JS_TRACKING = 'js_tracking';
    case SITEMAP = 'sitemap';
    case MANUAL = 'manual';
    case CMS_CONNECTOR = 'cms_connector';
    case API = 'api';
    case SERP = 'serp';
    case GEO_CITATION = 'geo_citation';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
