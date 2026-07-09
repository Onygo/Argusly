<?php

namespace App\Support\Intelligence;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;

class TimeWindowFactory
{
    public function __construct(private readonly CarbonInterface|string|null $clock = null)
    {
    }

    public static function fixed(CarbonInterface|string $now): self
    {
        return new self($now);
    }

    public function now(?string $timezone = null): CarbonImmutable
    {
        $timezone = self::normalizeTimezone($timezone);

        if ($this->clock instanceof CarbonInterface || is_string($this->clock)) {
            return $this->date($this->clock, $timezone)->timezone($timezone);
        }

        return CarbonImmutable::now($timezone);
    }

    public function today(?string $timezone = null, string $granularity = TimeWindow::GRANULARITY_DAILY): TimeWindow
    {
        return TimeWindow::between(
            $this->now($timezone),
            $this->now($timezone),
            $this->granularity($granularity),
        );
    }

    public function yesterday(?string $timezone = null, string $granularity = TimeWindow::GRANULARITY_DAILY): TimeWindow
    {
        $date = $this->now($timezone)->subDay();

        return TimeWindow::between($date, $date, $this->granularity($granularity));
    }

    public function lastDays(int $days, CarbonInterface|string|null $to = null, ?string $timezone = null, string $granularity = TimeWindow::GRANULARITY_DAILY): TimeWindow
    {
        return $this->rolling($days, $to, $timezone, $granularity);
    }

    public function rolling(int $periods, CarbonInterface|string|null $to = null, ?string $timezone = null, string $granularity = TimeWindow::GRANULARITY_DAILY): TimeWindow
    {
        $timezone = self::normalizeTimezone($timezone);
        $end = $to === null ? $this->now($timezone) : $this->date($to, $timezone);

        return TimeWindow::rolling($end, max(1, $periods), $this->granularity($granularity));
    }

    public function custom(
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        ?string $timezone = null,
        string $granularity = TimeWindow::GRANULARITY_DAILY,
    ): TimeWindow {
        $timezone = self::normalizeTimezone($timezone);

        return TimeWindow::between(
            $this->date($from, $timezone),
            $this->date($to, $timezone),
            $this->granularity($granularity),
        );
    }

    /**
     * @param  array<string, mixed>|object  $source
     */
    public function campaignWindow(array|object $source, ?string $timezone = null, string $granularity = TimeWindow::GRANULARITY_DAILY): TimeWindow
    {
        $from = $this->firstValue($source, [
            'period_start',
            'from',
            'start',
            'starts_on',
            'start_date',
            'planned_start_date',
            'scheduled_start_at',
            'started_at',
        ]);
        $to = $this->firstValue($source, [
            'period_end',
            'to',
            'end',
            'ends_on',
            'end_date',
            'planned_end_date',
            'scheduled_end_at',
            'ended_at',
        ]);

        if ($from === null || $to === null) {
            throw new InvalidArgumentException('A campaign window requires start and end values.');
        }

        return $this->custom($from, $to, $timezone, $granularity);
    }

    /**
     * @param  array<string, mixed>|object  $source
     */
    public function releaseWindow(array|object $source, ?string $timezone = null, string $granularity = TimeWindow::GRANULARITY_DAILY): TimeWindow
    {
        $releaseAt = $this->firstValue($source, [
            'release_at',
            'released_at',
            'release_date',
            'launch_at',
            'launched_at',
            'launch_date',
            'published_at',
            'date',
        ]);

        if ($releaseAt === null) {
            throw new InvalidArgumentException('A release window requires a release date.');
        }

        $timezone = self::normalizeTimezone($timezone);
        $date = $this->date($releaseAt, $timezone);
        $daysBefore = max(0, (int) ($this->firstValue($source, ['days_before', 'before_days', 'window_days_before']) ?? 0));
        $daysAfter = max(0, (int) ($this->firstValue($source, ['days_after', 'after_days', 'window_days_after']) ?? 0));

        return $this->custom($date->subDays($daysBefore), $date->addDays($daysAfter), $timezone, $granularity);
    }

    public static function normalizeTimezone(?string $timezone): string
    {
        $timezone = trim((string) $timezone);

        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        $fallback = trim((string) config('app.timezone', 'UTC'));

        return in_array($fallback, timezone_identifiers_list(), true) ? $fallback : 'UTC';
    }

    public function date(CarbonInterface|string $value, ?string $timezone = null): CarbonImmutable
    {
        $timezone = self::normalizeTimezone($timezone);

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::parse($value->toDateTimeString(), $value->getTimezone())
                ->timezone($timezone);
        }

        return CarbonImmutable::parse($value, new DateTimeZone($timezone));
    }

    private function granularity(string $granularity): string
    {
        return match ($granularity) {
            TimeWindow::GRANULARITY_WEEKLY => TimeWindow::GRANULARITY_WEEKLY,
            TimeWindow::GRANULARITY_MONTHLY => TimeWindow::GRANULARITY_MONTHLY,
            default => TimeWindow::GRANULARITY_DAILY,
        };
    }

    /**
     * @param  array<string, mixed>|object  $source
     * @param  array<int, string>  $keys
     */
    private function firstValue(array|object $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($source, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
