<?php

namespace App\Support\Intelligence;

use InvalidArgumentException;

class TimeWindowComparison
{
    public const NONE = 'none';
    public const PREVIOUS_PERIOD = 'previous_period';
    public const SAME_PERIOD_PREVIOUS_YEAR = 'same_period_previous_year';

    public function __construct(
        public readonly TimeWindow $current,
        public readonly ?TimeWindow $comparison,
        public readonly string $type = self::NONE,
    ) {
    }

    public static function none(TimeWindow $window): self
    {
        return new self($window, null, self::NONE);
    }

    public static function previousPeriod(TimeWindow $window): self
    {
        return new self($window, $window->previous(), self::PREVIOUS_PERIOD);
    }

    public static function samePeriodPreviousYear(TimeWindow $window): self
    {
        return new self($window, $window->samePeriodPreviousYear(), self::SAME_PERIOD_PREVIOUS_YEAR);
    }

    public static function for(TimeWindow $window, self|string|null $comparison): self
    {
        if ($comparison instanceof self) {
            return $comparison;
        }

        $type = self::normalizeType($comparison);

        return match ($type) {
            self::NONE => self::none($window),
            self::PREVIOUS_PERIOD => self::previousPeriod($window),
            self::SAME_PERIOD_PREVIOUS_YEAR => self::samePeriodPreviousYear($window),
            default => throw new InvalidArgumentException('Unsupported time window comparison: '.$type),
        };
    }

    /**
     * @return array{type:string,current:array<string,mixed>,comparison:?array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'current' => $this->current->toArray(),
            'comparison' => $this->comparison?->toArray(),
        ];
    }

    private static function normalizeType(string|null $comparison): string
    {
        $value = str($comparison ?? self::NONE)
            ->lower()
            ->trim()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        return match ($value) {
            '', 'none', 'no_comparison' => self::NONE,
            'previous', 'previous_period', 'prior_period' => self::PREVIOUS_PERIOD,
            'same_period_previous_year', 'same_period_last_year', 'previous_year', 'year_over_year', 'yoy' => self::SAME_PERIOD_PREVIOUS_YEAR,
            default => $value,
        };
    }
}
