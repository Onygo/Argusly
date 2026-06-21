<?php

namespace App\Enums;

enum FaqPageType: string
{
    case HOMEPAGE = 'homepage';
    case PLATFORM = 'platform';
    case SOLUTION = 'solution';
    case MARKET = 'market';
    case PRICING = 'pricing';
    case CONTACT = 'contact';
    case SECURITY = 'security';
    case RESOURCE = 'resource';
    case BLOG = 'blog';
    case LEGAL = 'legal';

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
