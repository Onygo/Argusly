<?php

namespace App\Enums;

enum FaqSearchIntent: string
{
    case INFORMATIONAL = 'informational';
    case COMMERCIAL = 'commercial';
    case COMPARISON = 'comparison';
    case TRANSACTIONAL = 'transactional';

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
