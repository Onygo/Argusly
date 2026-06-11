<?php

namespace App\Enums;

enum GrowthProgramStatus: string
{
    case DETECTED = 'detected';
    case QUALIFIED = 'qualified';
    case PLANNED = 'planned';
    case BRIEFED = 'briefed';
    case DRAFTING = 'drafting';
    case REVIEW = 'review';
    case SCHEDULED = 'scheduled';
    case PUBLISHED = 'published';
    case MEASURED = 'measured';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public function progress(): int
    {
        return match ($this) {
            self::DETECTED => 10,
            self::QUALIFIED => 20,
            self::PLANNED => 35,
            self::BRIEFED => 45,
            self::DRAFTING => 55,
            self::REVIEW => 70,
            self::SCHEDULED => 82,
            self::PUBLISHED => 92,
            self::MEASURED => 100,
        };
    }

    public function timestampColumn(): string
    {
        return $this->value.'_at';
    }

    public function canTransitionTo(self $target): bool
    {
        return array_search($target, self::cases(), true) >= array_search($this, self::cases(), true);
    }
}
