<?php

namespace App\Support\Intelligence;

use App\Models\ClientSite;
use App\Models\Workspace;
use Carbon\CarbonInterface;

class TimeWindowResolver
{
    public function __construct(private readonly ?TimeWindowFactory $factory = null)
    {
    }

    public static function fixed(CarbonInterface|string $now): self
    {
        return new self(TimeWindowFactory::fixed($now));
    }

    /**
     * @param  TimeWindowPreset|string|array<string, mixed>|null  $preset
     * @param  array<string, mixed>  $options
     */
    public function resolve(
        TimeWindowPreset|string|array|null $preset = null,
        array $options = [],
        ?Workspace $workspace = null,
        ?ClientSite $clientSite = null,
    ): TimeWindow {
        if (is_array($preset)) {
            $options = array_replace($preset, $options);
            $preset = $options['preset'] ?? null;
        }

        $defaultPreset = $this->defaultPreset($options);
        $preset = TimeWindowPreset::normalize($preset, $defaultPreset);
        $timezone = $this->timezone($options, $workspace, $clientSite);
        $granularity = (string) ($options['granularity'] ?? TimeWindow::GRANULARITY_DAILY);

        return match ($preset) {
            TimeWindowPreset::TODAY => $this->factory()->today($timezone, $granularity),
            TimeWindowPreset::YESTERDAY => $this->factory()->yesterday($timezone, $granularity),
            TimeWindowPreset::LAST_7_DAYS => $this->factory()->lastDays(7, $this->option($options, ['to', 'period_end', 'date']), $timezone, $granularity),
            TimeWindowPreset::LAST_28_DAYS => $this->factory()->lastDays(28, $this->option($options, ['to', 'period_end', 'date']), $timezone, $granularity),
            TimeWindowPreset::ROLLING => $this->factory()->rolling($this->periods($options), $this->option($options, ['to', 'period_end', 'date']), $timezone, $granularity),
            TimeWindowPreset::CAMPAIGN_WINDOW => $this->factory()->campaignWindow($this->source($options, 'campaign'), $timezone, $granularity),
            TimeWindowPreset::RELEASE_WINDOW => $this->factory()->releaseWindow($this->source($options, 'release'), $timezone, $granularity),
            TimeWindowPreset::CUSTOM_RANGE => $this->factory()->custom(
                $this->requiredOption($options, ['from', 'period_start', 'start', 'start_date']),
                $this->requiredOption($options, ['to', 'period_end', 'end', 'end_date']),
                $timezone,
                $granularity,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function resolveComparison(
        TimeWindowPreset|string|array|null $preset = null,
        array $options = [],
        ?Workspace $workspace = null,
        ?ClientSite $clientSite = null,
    ): TimeWindowComparison {
        if (is_array($preset)) {
            $options = array_replace($preset, $options);
            $preset = $options['preset'] ?? null;
        }

        $window = $this->resolve($preset, $options, $workspace, $clientSite);

        return TimeWindowComparison::for($window, $options['comparison'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function timezone(array $options = [], ?Workspace $workspace = null, ?ClientSite $clientSite = null): string
    {
        foreach ([
            $options['timezone'] ?? null,
            data_get($options, 'time_zone'),
            data_get($options, 'context.timezone'),
            data_get($clientSite, 'timezone'),
            data_get($clientSite, 'time_zone'),
            data_get($clientSite, 'connector_meta.timezone'),
            data_get($clientSite, 'automation_settings.timezone'),
            data_get($workspace, 'timezone'),
            data_get($workspace, 'time_zone'),
            data_get($workspace, 'visual_settings.timezone'),
        ] as $timezone) {
            $timezone = trim((string) $timezone);

            if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
                return $timezone;
            }
        }

        return TimeWindowFactory::normalizeTimezone(null);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function defaultPreset(array $options): TimeWindowPreset
    {
        if ($this->option($options, ['from', 'period_start', 'start', 'start_date']) !== null) {
            return TimeWindowPreset::CUSTOM_RANGE;
        }

        if ($this->option($options, ['campaign']) !== null) {
            return TimeWindowPreset::CAMPAIGN_WINDOW;
        }

        if ($this->option($options, ['release', 'release_at', 'released_at', 'release_date', 'published_at']) !== null) {
            return TimeWindowPreset::RELEASE_WINDOW;
        }

        if ($this->option($options, ['periods', 'days', 'rolling_window', 'window_days']) !== null) {
            return TimeWindowPreset::ROLLING;
        }

        return TimeWindowPreset::LAST_28_DAYS;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function periods(array $options): int
    {
        return max(1, (int) ($this->option($options, [
            'periods',
            'days',
            'rolling_periods',
            'rolling_days',
            'rolling_window',
            'window_days',
        ]) ?? 1));
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<int, string>  $keys
     */
    private function option(array $options, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($options, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<int, string>  $keys
     */
    private function requiredOption(array $options, array $keys): mixed
    {
        return $this->option($options, $keys) ?? $this->factory()->now($this->timezone($options));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|object
     */
    private function source(array $options, string $key): array|object
    {
        $source = data_get($options, $key);

        return is_array($source) || is_object($source) ? $source : $options;
    }

    private function factory(): TimeWindowFactory
    {
        return $this->factory ?? new TimeWindowFactory();
    }
}
