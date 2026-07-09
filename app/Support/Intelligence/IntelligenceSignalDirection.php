<?php

namespace App\Support\Intelligence;

enum IntelligenceSignalDirection: string
{
    case GROWTH = 'growth';
    case DECLINE = 'decline';
    case NEUTRAL = 'neutral';
    case MIXED = 'mixed';
    case INSUFFICIENT_DATA = 'insufficient_data';

    public static function normalize(self|string|null $direction, ?float $delta = null): self
    {
        if ($direction instanceof self) {
            return $direction;
        }

        $normalized = is_string($direction)
            ? str($direction)->lower()->trim()->slug('_')->toString()
            : '';

        return match ($normalized) {
            'growth', 'grew', 'up', 'increase', 'increased', 'positive' => self::GROWTH,
            'decline', 'declined', 'down', 'decrease', 'decreased', 'negative' => self::DECLINE,
            'neutral', 'flat', 'unchanged', 'no_change' => self::NEUTRAL,
            'mixed', 'conflict', 'conflicted' => self::MIXED,
            'insufficient', 'insufficient_data', 'unknown', 'missing_data' => self::INSUFFICIENT_DATA,
            default => self::fromDelta($delta),
        };
    }

    public static function fromDelta(?float $delta, float $threshold = 0.0): self
    {
        if ($delta === null) {
            return self::INSUFFICIENT_DATA;
        }

        $threshold = abs($threshold);

        return match (true) {
            $delta > $threshold => self::GROWTH,
            $delta < -$threshold => self::DECLINE,
            default => self::NEUTRAL,
        };
    }
}
