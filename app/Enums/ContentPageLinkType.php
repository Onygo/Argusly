<?php

namespace App\Enums;

enum ContentPageLinkType: string
{
    case OBSERVED_SOURCE = 'observed_source';
    case PUBLICATION_URL = 'publication_url';
    case ACTIVATION_TARGET = 'activation_target';
    case CANONICAL_EQUIVALENT = 'canonical_equivalent';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
