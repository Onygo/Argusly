<?php

namespace App\Support\Intelligence;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class TimeWindow
{
    public const GRANULARITY_DAILY = 'daily';
    public const GRANULARITY_WEEKLY = 'weekly';
    public const GRANULARITY_MONTHLY = 'monthly';

    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $granularity = self::GRANULARITY_DAILY,
    ) {
    }

    public static function between(
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        string $granularity = self::GRANULARITY_DAILY,
    ): self {
        return new self(
            start: self::periodStart(self::immutable($from), $granularity),
            end: self::periodEnd(self::immutable($to), $granularity),
            granularity: $granularity,
        );
    }

    public static function rolling(
        CarbonInterface|string $to,
        int $periods,
        string $granularity = self::GRANULARITY_DAILY,
    ): self {
        $end = self::periodEnd(self::immutable($to), $granularity);
        $periods = max(1, $periods);

        $start = match ($granularity) {
            self::GRANULARITY_WEEKLY => self::periodStart($end, $granularity)->subWeeks($periods - 1),
            self::GRANULARITY_MONTHLY => self::periodStart($end, $granularity)->subMonthsNoOverflow($periods - 1),
            default => self::periodStart($end, $granularity)->subDays($periods - 1),
        };

        return new self($start, $end, $granularity);
    }

    public function previous(): self
    {
        $periods = $this->periodsCount();

        $start = match ($this->granularity) {
            self::GRANULARITY_WEEKLY => $this->start->subWeeks($periods),
            self::GRANULARITY_MONTHLY => $this->start->subMonthsNoOverflow($periods),
            default => $this->start->subDays($periods),
        };

        return new self($start, $this->start->subSecond(), $this->granularity);
    }

    public function samePeriodPreviousYear(): self
    {
        return new self(
            $this->start->subYearNoOverflow(),
            $this->end->subYearNoOverflow(),
            $this->granularity,
        );
    }

    public function periodsCount(): int
    {
        $start = self::periodStart($this->start, $this->granularity);
        $end = self::periodStart($this->end, $this->granularity);

        return match ($this->granularity) {
            self::GRANULARITY_WEEKLY => max(1, (int) $start->diffInWeeks($end) + 1),
            self::GRANULARITY_MONTHLY => max(1, (int) $start->diffInMonths($end) + 1),
            default => max(1, (int) $start->diffInDays($end) + 1),
        };
    }

    public function contains(CarbonInterface|string $date): bool
    {
        $date = self::immutable($date);

        return $date->greaterThanOrEqualTo($this->start) && $date->lessThanOrEqualTo($this->end);
    }

    public function bucketKey(CarbonInterface|string $date): string
    {
        return self::bucketKeyFor($date, $this->granularity);
    }

    public static function bucketKeyFor(CarbonInterface|string $date, string $granularity): string
    {
        return self::periodStart(self::immutable($date), $granularity)->toDateString();
    }

    public static function periodStart(CarbonInterface|string $date, string $granularity): CarbonImmutable
    {
        $date = self::immutable($date);

        return match ($granularity) {
            self::GRANULARITY_WEEKLY => $date->startOfWeek(),
            self::GRANULARITY_MONTHLY => $date->startOfMonth(),
            default => $date->startOfDay(),
        };
    }

    public static function periodEnd(CarbonInterface|string $date, string $granularity): CarbonImmutable
    {
        $date = self::immutable($date);

        return match ($granularity) {
            self::GRANULARITY_WEEKLY => $date->endOfWeek(),
            self::GRANULARITY_MONTHLY => $date->endOfMonth(),
            default => $date->endOfDay(),
        };
    }

    public static function immutable(CarbonInterface|string $date): CarbonImmutable
    {
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::parse($date->toDateTimeString(), $date->getTimezone());
        }

        return CarbonImmutable::parse($date);
    }

    /**
     * @return array{period_start:string,period_end:string,granularity:string,periods_count:int}
     */
    public function toArray(): array
    {
        return [
            'period_start' => $this->start->toDateTimeString(),
            'period_end' => $this->end->toDateTimeString(),
            'granularity' => $this->granularity,
            'periods_count' => $this->periodsCount(),
        ];
    }
}
