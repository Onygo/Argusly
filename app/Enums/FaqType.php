<?php

namespace App\Enums;

enum FaqType: string
{
    case CATEGORY = 'category';
    case PLATFORM = 'platform';
    case SOLUTION = 'solution';
    case MARKET = 'market';
    case PRODUCT = 'product';
    case PRICING = 'pricing';
    case SECURITY = 'security';
    case GOVERNANCE = 'governance';
    case IMPLEMENTATION = 'implementation';
    case COMPARISON = 'comparison';
    case RESOURCE = 'resource';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
