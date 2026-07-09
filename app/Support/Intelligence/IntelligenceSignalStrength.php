<?php

namespace App\Support\Intelligence;

enum IntelligenceSignalStrength: string
{
    case INSUFFICIENT = 'insufficient';
    case WEAK = 'weak';
    case MODERATE = 'moderate';
    case STRONG = 'strong';

    public static function normalize(
        self|string|null $strength,
        float $confidence,
        IntelligenceSignalDirection $direction,
    ): self {
        if ($strength instanceof self) {
            return $strength;
        }

        $normalized = is_string($strength)
            ? str($strength)->lower()->trim()->slug('_')->toString()
            : '';

        return match ($normalized) {
            'insufficient', 'insufficient_data', 'unknown' => self::INSUFFICIENT,
            'weak', 'low' => self::WEAK,
            'moderate', 'medium' => self::MODERATE,
            'strong', 'high' => self::STRONG,
            default => self::fromConfidence($confidence, $direction),
        };
    }

    public static function fromConfidence(float $confidence, IntelligenceSignalDirection $direction): self
    {
        if ($direction === IntelligenceSignalDirection::INSUFFICIENT_DATA || $confidence < 0.25) {
            return self::INSUFFICIENT;
        }

        return match (true) {
            $confidence < 0.5 => self::WEAK,
            $confidence < 0.75 => self::MODERATE,
            default => self::STRONG,
        };
    }
}
