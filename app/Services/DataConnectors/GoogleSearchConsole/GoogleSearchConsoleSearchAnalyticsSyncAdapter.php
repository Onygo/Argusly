<?php

namespace App\Services\DataConnectors\GoogleSearchConsole;

use App\Models\MarketingObservation;
use App\Services\DataConnectors\ConnectorFatalSyncException;
use App\Services\DataConnectors\ConnectorRecoverableSyncException;
use App\Services\DataConnectors\ConnectorProviderHttpClient;
use App\Services\DataConnectors\ConnectorSyncAdapter;
use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use App\Services\DataConnectors\ConnectorSyncPage;
use App\Support\MarketingMetadataRedactor;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

class GoogleSearchConsoleSearchAnalyticsSyncAdapter implements ConnectorSyncAdapter
{
    private const DEFAULT_DIMENSIONS = ['date', 'query', 'page', 'country', 'device', 'searchAppearance'];

    private const METRICS = [
        'clicks' => ['unit' => 'count'],
        'impressions' => ['unit' => 'count'],
        'ctr' => ['unit' => 'ratio'],
        'position' => ['unit' => 'rank'],
    ];

    public function __construct(private readonly ConnectorProviderHttpClient $http)
    {
    }

    public function fetch(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): ConnectorSyncPage
    {
        $dateRange = $this->dateRange($context, $cursor);
        $dimensions = $this->dimensions($context);
        $rowLimit = $this->rowLimit($context);
        $startRow = $this->startRow($cursor, $dateRange);

        $response = $this->http->post(
            $context->plan->account,
            $this->searchAnalyticsUrl($context),
            [
                'startDate' => $dateRange['start'],
                'endDate' => $dateRange['end'],
                'dimensions' => $dimensions,
                'rowLimit' => $rowLimit,
                'startRow' => $startRow,
            ],
            timeout: $this->timeoutSeconds(),
        );

        $this->throwIfFailed($response);

        $rows = array_values(array_filter(
            (array) $response->json('rows', []),
            fn (mixed $row): bool => is_array($row)
        ));

        $hasMore = count($rows) >= $rowLimit;
        $nextCursor = $hasMore
            ? new ConnectorSyncCursor([
                'start_row' => $startRow + $rowLimit,
                'date_range' => $dateRange,
            ])
            : new ConnectorSyncCursor([
                'start_row' => 0,
                'date_range' => $dateRange,
                'last_synced_date' => $dateRange['end'],
            ]);

        return new ConnectorSyncPage(
            observations: $this->observations($context, $rows, $dimensions, $dateRange),
            nextCursor: $nextCursor,
            hasMore: $hasMore,
            metadata: [
                'provider' => 'google_search_console',
                'start_row' => $startRow,
                'row_count' => count($rows),
            ],
            rateLimit: $this->rateLimit($response),
            rawRecords: $this->rawRecords($context, $rows, $dimensions, $dateRange),
        );
    }

    /**
     * @return array{start: string, end: string}
     */
    private function dateRange(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): array
    {
        $start = $context->plan->dateRangeStart
            ? Carbon::instance($context->plan->dateRangeStart)->toDateString()
            : (string) ($cursor->get('last_synced_date') ?: now()->subDays(3)->toDateString());

        $end = $context->plan->dateRangeEnd
            ? Carbon::instance($context->plan->dateRangeEnd)->toDateString()
            : now()->subDay()->toDateString();

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return list<string>
     */
    private function dimensions(ConnectorSyncContext $context): array
    {
        $configured = $context->plan->dimensions
            ?: (array) data_get($context->plan->dataset->sync_config_json, 'dimensions', []);

        $dimensions = array_values(array_filter($configured, fn (mixed $dimension): bool => is_string($dimension) && trim($dimension) !== ''));

        return $dimensions === [] ? self::DEFAULT_DIMENSIONS : $dimensions;
    }

    private function rowLimit(ConnectorSyncContext $context): int
    {
        return max(1, min(25000, (int) $context->plan->pageSize));
    }

    /**
     * @param array{start: string, end: string} $dateRange
     */
    private function startRow(ConnectorSyncCursor $cursor, array $dateRange): int
    {
        if ($cursor->get('date_range') !== null && $cursor->get('date_range') !== $dateRange) {
            return 0;
        }

        return max(0, (int) $cursor->get('start_row', 0));
    }

    private function searchAnalyticsUrl(ConnectorSyncContext $context): string
    {
        $siteUrl = trim((string) (
            data_get($context->plan->dataset->config_json, 'site_url')
            ?: $context->plan->dataset->external_dataset_id
        ));

        if ($siteUrl === '') {
            throw new ConnectorFatalSyncException('Google Search Console dataset is missing a site URL.');
        }

        return $this->apiBaseUrl().'/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param list<string> $dimensions
     * @param array{start: string, end: string} $dateRange
     * @return array<int, array<string, mixed>>
     */
    private function observations(ConnectorSyncContext $context, array $rows, array $dimensions, array $dateRange): array
    {
        $observations = [];

        foreach ($rows as $row) {
            $dimensionValues = $this->dimensionValues((array) ($row['keys'] ?? []), $dimensions);
            $periodDate = (string) ($dimensionValues['date'] ?? $dateRange['start']);
            $periodStart = Carbon::parse($periodDate)->startOfDay();
            $periodEnd = Carbon::parse($periodDate)->endOfDay();

            foreach (self::METRICS as $metricKey => $definition) {
                if (! array_key_exists($metricKey, $row) || ! is_numeric($row[$metricKey])) {
                    continue;
                }

                $observations[] = [
                    'metric_key' => $metricKey,
                    'metric_value' => (float) $row[$metricKey],
                    'unit' => $definition['unit'],
                    'period_start' => $periodStart->toDateTimeString(),
                    'period_end' => $periodEnd->toDateTimeString(),
                    'granularity' => MarketingObservation::GRANULARITY_DAILY,
                    'observed_at' => now()->toDateTimeString(),
                    'external_id' => $this->externalId($context, $metricKey, $dimensionValues, $periodDate),
                    'dimensions' => $dimensionValues,
                    'source_metadata' => [
                        'provider' => 'google_search_console',
                        'dataset_type' => 'search_analytics',
                        'site_url' => data_get($context->plan->dataset->config_json, 'site_url') ?: $context->plan->dataset->external_dataset_id,
                    ],
                    'raw_metadata' => MarketingMetadataRedactor::redact([
                        'provider_row_keys' => (array) ($row['keys'] ?? []),
                        'available_metrics' => array_values(array_intersect(array_keys($row), array_keys(self::METRICS))),
                    ]),
                ];
            }
        }

        return $observations;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param list<string> $dimensions
     * @param array{start: string, end: string} $dateRange
     * @return array<int, array<string, mixed>>
     */
    private function rawRecords(ConnectorSyncContext $context, array $rows, array $dimensions, array $dateRange): array
    {
        return collect($rows)
            ->map(function (array $row) use ($context, $dimensions, $dateRange): array {
                $dimensionValues = $this->dimensionValues((array) ($row['keys'] ?? []), $dimensions);
                $periodDate = (string) ($dimensionValues['date'] ?? $dateRange['start']);

                return [
                    'record_type' => 'search_analytics',
                    'external_record_id' => $this->externalId($context, 'raw', $dimensionValues, $periodDate),
                    'period_start' => Carbon::parse($periodDate)->startOfDay()->toDateTimeString(),
                    'period_end' => Carbon::parse($periodDate)->endOfDay()->toDateTimeString(),
                    'observed_at' => now()->toDateTimeString(),
                    'payload' => $row,
                    'metadata' => [
                        'provider' => 'google_search_console',
                        'dimensions' => $dimensions,
                        'site_url' => data_get($context->plan->dataset->config_json, 'site_url')
                            ?: $context->plan->dataset->external_dataset_id,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, mixed> $keys
     * @param list<string> $dimensions
     * @return array<string, string>
     */
    private function dimensionValues(array $keys, array $dimensions): array
    {
        $values = [];

        foreach ($dimensions as $index => $dimension) {
            if (array_key_exists($index, $keys)) {
                $values[$dimension] = (string) $keys[$index];
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $dimensionValues
     */
    private function externalId(ConnectorSyncContext $context, string $metricKey, array $dimensionValues, string $periodDate): string
    {
        ksort($dimensionValues);

        return 'gsc:'.hash('sha256', json_encode([
            'dataset' => $context->plan->dataset->external_dataset_id,
            'metric' => $metricKey,
            'date' => $periodDate,
            'dimensions' => $dimensionValues,
        ], JSON_THROW_ON_ERROR));
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = 'Google Search Console Search Analytics request failed with status '.$response->status().'.';

        if ($response->status() === 429 || $response->status() >= 500) {
            throw new ConnectorRecoverableSyncException($message);
        }

        throw new ConnectorFatalSyncException($message);
    }

    /**
     * @return array<string, mixed>
     */
    private function rateLimit(Response $response): array
    {
        return array_filter([
            'limit' => $response->header('X-RateLimit-Limit'),
            'remaining' => $response->header('X-RateLimit-Remaining'),
            'reset' => $response->header('X-RateLimit-Reset'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) config('data_connectors.providers.google_search_console.config_json.api.base_url', 'https://www.googleapis.com/webmasters/v3'), '/');
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('data_connectors.providers.google_search_console.config_json.api.timeout_seconds', 15));
    }
}
