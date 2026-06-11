<?php

namespace App\Enums;

enum SignalEntityType: string
{
    case BRAND = 'brand';
    case COMPETITOR = 'competitor';
    case COMPANY = 'company';
    case PERSON = 'person';
    case PRODUCT = 'product';
    case TOPIC = 'topic';
    case DOMAIN = 'domain';
    case SOURCE = 'source';

    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
