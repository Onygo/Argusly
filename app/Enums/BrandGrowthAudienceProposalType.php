<?php

namespace App\Enums;

enum BrandGrowthAudienceProposalType: string
{
    case AUDIENCE = 'audience';
    case PERSONA = 'persona';
    case BUYING_COMMITTEE_ROLE = 'buying_committee_role';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
