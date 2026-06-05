<?php

namespace App\Enums\Billing;

enum PlanChangeTiming: string
{
    case NEXT_PERIOD = 'next_period';
    case IMMEDIATE_PRORATED = 'immediate_prorated';

    public function toStrategyValue(): string
    {
        return match ($this) {
            self::NEXT_PERIOD => 'next_period',
            self::IMMEDIATE_PRORATED => 'immediate_proration',
        };
    }

    public function isImmediateProrated(): bool
    {
        return $this === self::IMMEDIATE_PRORATED;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromLegacyStrategy(?string $value): ?self
    {
        return match ($value) {
            'next_period' => self::NEXT_PERIOD,
            'immediate_prorated', 'immediate_proration' => self::IMMEDIATE_PRORATED,
            default => null,
        };
    }
}
