<?php

namespace App\Services\DataConnectors\GoogleAnalytics4;

use App\Models\MarketingObservation;
use App\Services\DataConnectors\ConnectorFatalSyncException;
use App\Services\DataConnectors\ConnectorRecoverableSyncException;
use App\Services\DataConnectors\ConnectorSyncAdapter;
use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use App\Services\DataConnectors\ConnectorSyncPage;
use App\Services\DataConnectors\ConnectorTokenVault;
use App\Support\MarketingMetadataRedactor;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class GoogleAnalytics4ReportingSyncAdapter implements ConnectorSyncAdapter
{
    private const DEFAULT_DIMENSIONS = [
        'date',
        'pagePath',
        'sessionSource',
        'sessionMedium',
        'sessionCampaign',
        'deviceCategory',
        'country',
        'defaultChannelGroup',
    ];

    private const DEFAULT_METRICS = [
        'sessions',
        'users',
        'newUsers',
        'engagedSessions',
        'engagementRate',
        'averageSessionDuration',
        'eventCount',
        'keyEvents',
    ];

    private const API_METRIC_ALIASES = [
        'users' => 'activeUsers',
    ];

    private const METRIC_DEFINITIONS = [
        'sessions' => ['metric_key' => 'sessions', 'unit' => 'count'],
        'users' => ['metric_key' => 'users', 'unit' => 'count'],
        'activeUsers' => ['metric_key' => 'users', 'unit' => 'count'],
        'totalUsers' => ['metric_key' => 'users', 'unit' => 'count'],
        'newUsers' => ['metric_key' => 'newUsers', 'unit' => 'count'],
        'engagedSessions' => ['metric_key' => 'engagedSessions', 'unit' => 'count'],
        'engagementRate' => ['metric_key' => 'engagementRate', 'unit' => 'ratio'],
        'averageSessionDuration' => ['metric_key' => 'averageSessionDuration', 'unit' => 'seconds'],
        'eventCount' => ['metric_key' => 'eventCount', 'unit' => 'count'],
        'conversions' => ['metric_key' => 'conversions', 'unit' => 'count'],
        'keyEvents' => ['metric_key' => 'keyEvents', 'unit' => 'count'],
    ];

    public function __construct(private readonly ConnectorTokenVault $tokens)
    {
    }

    public function fetch(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): ConnectorSyncPage
    {
        $token = $this->tokens->latestFor($context->plan->account);

        if ($token === null || trim((string) $token->access_token) === '') {
            throw new ConnectorFatalSyncException('Google Analytics 4 connector account does not have an access token.');
        }

        $dateRange = $this->dateRange($context, $cursor);
        $dimensions = $this->dimensions($context);
        $metrics = $this->metrics($context);
        $limit = $this->rowLimit($context);
        $offset = $this->offset($cursor, $dateRange);

        $response = Http::withToken((string) $token->access_token)
            ->acceptJson()
            ->timeout($this->timeoutSeconds())
            ->post($this->runReportUrl($context), $this->payload($context, $dateRange, $dimensions, $metrics, $limit, $offset));

        $this->throwIfFailed($response);

        $rows = array_values(array_filter(
            (array) $response->json('rows', []),
            fn (mixed $row): bool => is_array($row)
        ));
        $rowCount = $this->rowCount($response, $offset, count($rows));
        $nextOffset = $offset + count($rows);
        $hasMore = count($rows) > 0 && $nextOffset < $rowCount;
        $nextCursor = $hasMore
            ? new ConnectorSyncCursor([
                'offset' => $nextOffset,
                'date_range' => $dateRange,
            ])
            : new ConnectorSyncCursor([
                'offset' => 0,
                'date_range' => $dateRange,
                'last_synced_date' => $dateRange['end'],
            ]);

        $dimensionHeaders = $this->headers($response, 'dimensionHeaders', $dimensions);
        $metricHeaders = $this->headers($response, 'metricHeaders', $metrics);

        return new ConnectorSyncPage(
            observations: $this->observations($context, $rows, $dimensionHeaders, $metricHeaders, $dateRange),
            nextCursor: $nextCursor,
            hasMore: $hasMore,
            metadata: [
                'provider' => 'google_analytics_4',
                'offset' => $offset,
                'row_count' => count($rows),
                'total_row_count' => $rowCount,
            ],
            rateLimit: $this->rateLimit($response),
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
            ?: (array) data_get($context->plan->dataset->sync_config_json, 'dimensions', [])
            ?: (array) config('data_connectors.providers.google_analytics_4.config_json.sync.dimensions', []);

        $dimensions = array_values(array_unique(array_filter(
            $configured,
            fn (mixed $dimension): bool => is_string($dimension) && trim($dimension) !== ''
        )));

        return $dimensions === [] ? self::DEFAULT_DIMENSIONS : $dimensions;
    }

    /**
     * @return list<string>
     */
    private function metrics(ConnectorSyncContext $context): array
    {
        $configured = $context->plan->metrics
            ?: (array) data_get($context->plan->dataset->sync_config_json, 'metrics', [])
            ?: (array) config('data_connectors.providers.google_analytics_4.config_json.sync.metrics', []);

        $metrics = array_values(array_unique(array_map(
            fn (string $metric): string => self::API_METRIC_ALIASES[$metric] ?? $metric,
            array_values(array_filter($configured, fn (mixed $metric): bool => is_string($metric) && trim($metric) !== ''))
        )));

        return $metrics === [] ? array_map(
            fn (string $metric): string => self::API_METRIC_ALIASES[$metric] ?? $metric,
            self::DEFAULT_METRICS
        ) : $metrics;
    }

    private function rowLimit(ConnectorSyncContext $context): int
    {
        $configured = (int) config('data_connectors.providers.google_analytics_4.config_json.api.report_page_size', 10000);

        return max(1, min(100000, (int) ($context->plan->pageSize ?: $configured)));
    }

    /**
     * @param array{start: string, end: string} $dateRange
     */
    private function offset(ConnectorSyncCursor $cursor, array $dateRange): int
    {
        if ($cursor->get('date_range') !== null && $cursor->get('date_range') !== $dateRange) {
            return 0;
        }

        return max(0, (int) $cursor->get('offset', 0));
    }

    /**
     * @param array{start: string, end: string} $dateRange
     * @param list<string> $dimensions
     * @param list<string> $metrics
     * @return array<string, mixed>
     */
    private function payload(
        ConnectorSyncContext $context,
        array $dateRange,
        array $dimensions,
        array $metrics,
        int $limit,
        int $offset,
    ): array {
        $payload = [
            'dateRanges' => [[
                'startDate' => $dateRange['start'],
                'endDate' => $dateRange['end'],
            ]],
            'dimensions' => array_map(fn (string $name): array => ['name' => $name], $dimensions),
            'metrics' => array_map(fn (string $name): array => ['name' => $name], $metrics),
            'limit' => (string) $limit,
            'offset' => (string) $offset,
            'keepEmptyRows' => false,
            'returnPropertyQuota' => true,
        ];

        foreach (['dimensionFilter', 'metricFilter'] as $filterKey) {
            if (isset($context->plan->filters[$filterKey]) && is_array($context->plan->filters[$filterKey])) {
                $payload[$filterKey] = $context->plan->filters[$filterKey];
            }
        }

        return $payload;
    }

    private function runReportUrl(ConnectorSyncContext $context): string
    {
        $propertyName = trim((string) (
            data_get($context->plan->dataset->config_json, 'property')
            ?: data_get($context->plan->dataset->config_json, 'property_name')
            ?: $context->plan->dataset->external_dataset_id
        ));

        if ($propertyName === '') {
            throw new ConnectorFatalSyncException('Google Analytics 4 dataset is missing a property.');
        }

        if (ctype_digit($propertyName)) {
            $propertyName = 'properties/'.$propertyName;
        }

        if (! str_starts_with($propertyName, 'properties/')) {
            throw new ConnectorFatalSyncException('Google Analytics 4 dataset property must use the properties/{property_id} format.');
        }

        return $this->dataBaseUrl().'/'.trim($propertyName, '/').':runReport';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param list<string> $dimensionHeaders
     * @param list<string> $metricHeaders
     * @param array{start: string, end: string} $dateRange
     * @return array<int, array<string, mixed>>
     */
    private function observations(
        ConnectorSyncContext $context,
        array $rows,
        array $dimensionHeaders,
        array $metricHeaders,
        array $dateRange,
    ): array {
        $observations = [];

        foreach ($rows as $row) {
            $dimensionValues = $this->dimensionValues((array) ($row['dimensionValues'] ?? []), $dimensionHeaders);
            $periodDate = $this->periodDate((string) ($dimensionValues['date'] ?? ''), $dateRange);
            $periodStart = Carbon::parse($periodDate)->startOfDay();
            $periodEnd = Carbon::parse($periodDate)->endOfDay();

            if (array_key_exists('date', $dimensionValues)) {
                $dimensionValues['date'] = $periodDate;
            }

            foreach ($this->metricValues((array) ($row['metricValues'] ?? []), $metricHeaders) as $sourceMetric => $metricValue) {
                if (! is_numeric($metricValue) || ! isset(self::METRIC_DEFINITIONS[$sourceMetric])) {
                    continue;
                }

                $definition = self::METRIC_DEFINITIONS[$sourceMetric];
                $metricKey = $definition['metric_key'];

                $observations[] = [
                    'metric_key' => $metricKey,
                    'metric_value' => (float) $metricValue,
                    'unit' => $definition['unit'],
                    'period_start' => $periodStart->toDateTimeString(),
                    'period_end' => $periodEnd->toDateTimeString(),
                    'granularity' => MarketingObservation::GRANULARITY_DAILY,
                    'observed_at' => now()->toDateTimeString(),
                    'external_id' => $this->externalId($context, $sourceMetric, $metricKey, $dimensionValues, $periodDate),
                    'dimensions' => $dimensionValues,
                    'source_metadata' => [
                        'provider' => 'google_analytics_4',
                        'dataset_type' => 'report',
                        'property' => data_get($context->plan->dataset->config_json, 'property')
                            ?: $context->plan->dataset->external_dataset_id,
                        'source_metric' => $sourceMetric,
                    ],
                    'raw_metadata' => MarketingMetadataRedactor::redact([
                        'dimension_headers' => $dimensionHeaders,
                        'metric_headers' => $metricHeaders,
                        'available_metrics' => array_keys($this->metricValues((array) ($row['metricValues'] ?? []), $metricHeaders)),
                    ]),
                ];
            }
        }

        return $observations;
    }

    /**
     * @param array<int, mixed> $values
     * @param list<string> $headers
     * @return array<string, string>
     */
    private function dimensionValues(array $values, array $headers): array
    {
        $dimensions = [];

        foreach ($headers as $index => $header) {
            if (! array_key_exists($index, $values)) {
                continue;
            }

            $value = is_array($values[$index])
                ? ($values[$index]['value'] ?? null)
                : $values[$index];

            $dimensions[$header] = (string) $value;
        }

        return $dimensions;
    }

    /**
     * @param array<int, mixed> $values
     * @param list<string> $headers
     * @return array<string, string>
     */
    private function metricValues(array $values, array $headers): array
    {
        $metrics = [];

        foreach ($headers as $index => $header) {
            if (! array_key_exists($index, $values)) {
                continue;
            }

            $value = is_array($values[$index])
                ? ($values[$index]['value'] ?? null)
                : $values[$index];

            $metrics[$header] = (string) $value;
        }

        return $metrics;
    }

    /**
     * @param array{start: string, end: string} $dateRange
     */
    private function periodDate(string $dimensionDate, array $dateRange): string
    {
        $value = trim($dimensionDate);

        if (preg_match('/^\d{8}$/', $value) === 1) {
            return substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2);
        }

        if ($value !== '') {
            return Carbon::parse($value)->toDateString();
        }

        return $dateRange['start'];
    }

    /**
     * @param list<string> $fallback
     * @return list<string>
     */
    private function headers(Response $response, string $key, array $fallback): array
    {
        $headers = collect((array) $response->json($key, []))
            ->map(fn (mixed $header): ?string => is_array($header) ? ($header['name'] ?? null) : null)
            ->filter(fn (?string $header): bool => is_string($header) && trim($header) !== '')
            ->values()
            ->all();

        return $headers === [] ? $fallback : $headers;
    }

    private function rowCount(Response $response, int $offset, int $rows): int
    {
        $rowCount = $response->json('rowCount');

        if (is_numeric($rowCount)) {
            return max(0, (int) $rowCount);
        }

        return $offset + $rows;
    }

    /**
     * @param array<string, string> $dimensionValues
     */
    private function externalId(
        ConnectorSyncContext $context,
        string $sourceMetric,
        string $metricKey,
        array $dimensionValues,
        string $periodDate,
    ): string {
        ksort($dimensionValues);

        return 'ga4:'.hash('sha256', json_encode([
            'dataset' => $context->plan->dataset->external_dataset_id,
            'source_metric' => $sourceMetric,
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

        $message = 'Google Analytics 4 reporting request failed with status '.$response->status().'.';

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

    private function dataBaseUrl(): string
    {
        return rtrim((string) config('data_connectors.providers.google_analytics_4.config_json.api.data_base_url', 'https://analyticsdata.googleapis.com/v1beta'), '/');
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('data_connectors.providers.google_analytics_4.config_json.api.timeout_seconds', 15));
    }
}
