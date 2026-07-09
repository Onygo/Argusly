<?php

namespace App\Support\Intelligence;

enum CanonicalEntityType: string
{
    case COMPANY = 'company';
    case BRAND = 'brand';
    case PERSON = 'person';
    case PRODUCT = 'product';
    case COMPETITOR = 'competitor';
    case TOPIC = 'topic';
    case TECHNOLOGY = 'technology';
    case MARKET = 'market';
    case COUNTRY = 'country';
    case ORGANIZATION = 'organization';
    case DOMAIN = 'domain';
    case SOURCE = 'source';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
