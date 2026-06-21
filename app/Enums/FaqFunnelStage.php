<?php

namespace App\Enums;

enum FaqFunnelStage: string
{
    case AWARENESS = 'awareness';
    case CONSIDERATION = 'consideration';
    case DECISION = 'decision';

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
