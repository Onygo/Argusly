<?php

namespace App\Services\DataConnectors\LinkedIn;

use App\Models\MarketingObservation;
use App\Services\DataConnectors\ConnectorFatalSyncException;
use App\Services\DataConnectors\ConnectorProviderHttpClient;
use App\Services\DataConnectors\ConnectorRecoverableSyncException;
use App\Services\DataConnectors\ConnectorSyncAdapter;
use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use App\Services\DataConnectors\ConnectorSyncPage;
use App\Support\MarketingMetadataRedactor;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

class LinkedInAnalyticsSyncAdapter implements ConnectorSyncAdapter
{
    private const DEFAULT_RESOURCES = ['share_statistics', 'follower_statistics'];

    private const DEFAULT_DIMENSIONS = [
        'date',
        'organization',
        'post',
        'mediaType',
        'campaign',
        'content',
    ];

    private const RESOURCE_ENDPOINTS = [
        'share_statistics' => 'organizationalEntityShareStatistics',
        'follower_statistics' => 'organizationalEntityFollowerStatistics',
        'page_statistics' => 'organizationPageStatistics',
    ];

    private const SHARE_METRICS = [
        'impressions' => [
            'unit' => 'count',
            'paths' => ['impressions', 'impressionCount', 'organicImpressionCount', 'totalShareStatistics.impressionCount', 'totalShareStatistics.organicImpressionCount'],
        ],
        'clicks' => [
            'unit' => 'count',
            'paths' => ['clicks', 'clickCount', 'organicClickCount', 'totalShareStatistics.clickCount', 'totalShareStatistics.organicClickCount'],
        ],
        'reactions' => [
            'unit' => 'count',
            'paths' => ['reactions', 'reactionCount', 'likeCount', 'totalShareStatistics.likeCount', 'totalShareStatistics.reactionCount'],
        ],
        'comments' => [
            'unit' => 'count',
            'paths' => ['comments', 'commentCount', 'totalShareStatistics.commentCount'],
        ],
        'shares' => [
            'unit' => 'count',
            'paths' => ['shares', 'shareCount', 'totalShareStatistics.shareCount'],
        ],
        'engagementRate' => [
            'unit' => 'ratio',
            'paths' => ['engagementRate', 'engagement', 'totalShareStatistics.engagementRate', 'totalShareStatistics.engagement'],
        ],
    ];

    private const FOLLOWER_METRICS = [
        'followers' => [
            'unit' => 'count',
            'paths' => [
                'followers',
                'followerCount',
                'totalFollowerCount',
                'organicFollowerCount',
                'followerCounts.organicFollowerCount',
                'totalFollowerCounts.organicFollowerCount',
                'followerCounts.totalFollowerCount',
                'totalFollowerCounts.totalFollowerCount',
            ],
        ],
    ];

    public function __construct(private readonly ConnectorProviderHttpClient $http)
    {
    }

    public function fetch(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): ConnectorSyncPage
    {
        $dateRange = $this->dateRange($context, $cursor);
        $resources = $this->resources($context);
        $resourceIndex = $this->resourceIndex($cursor, $dateRange, count($resources));
        $resource = $resources[$resourceIndex];
        $count = $this->pageSize($context);
        $start = $this->start($cursor, $dateRange, $resourceIndex);

        $response = $this->http->get(
            $context->plan->account,
            $this->resourceUrl($resource),
            $this->resourceQuery($context, $dateRange, $start, $count),
            $this->headers(),
            $this->timeoutSeconds(),
        );

        $this->throwIfFailed($response, $resource);

        $rows = array_values(array_filter(
            (array) $response->json('elements', []),
            fn (mixed $row): bool => is_array($row)
        ));

        $resourceHasMore = $this->hasMore($response, $start, $count, count($rows));
        $hasNextResource = ! $resourceHasMore && $resourceIndex < count($resources) - 1;
        $nextCursor = $this->nextCursor(
            resources: $resources,
            resourceIndex: $resourceIndex,
            resourceHasMore: $resourceHasMore,
            hasNextResource: $hasNextResource,
            nextStart: $start + count($rows),
            dateRange: $dateRange,
        );

        return new ConnectorSyncPage(
            observations: $this->observations($context, $resource, $rows, $dateRange),
            nextCursor: $nextCursor,
            hasMore: $resourceHasMore || $hasNextResource,
            metadata: [
                'provider' => 'linkedin',
                'resource' => $resource,
                'start' => $start,
                'row_count' => count($rows),
            ],
            rateLimit: $this->rateLimit($response),
            rawRecords: $this->rawRecords($context, $resource, $rows, $dateRange),
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
    private function resources(ConnectorSyncContext $context): array
    {
        $configured = (array) data_get($context->plan->dataset->sync_config_json, 'resources', [])
            ?: (array) config('data_connectors.providers.linkedin.config_json.sync.resources', []);

        $resources = array_values(array_unique(array_filter(
            $configured,
            fn (mixed $resource): bool => is_string($resource) && isset(self::RESOURCE_ENDPOINTS[$resource])
        )));

        return $resources === [] ? self::DEFAULT_RESOURCES : $resources;
    }

    /**
     * @return list<string>
     */
    private function dimensions(ConnectorSyncContext $context): array
    {
        $configured = $context->plan->dimensions
            ?: (array) data_get($context->plan->dataset->sync_config_json, 'dimensions', [])
            ?: (array) config('data_connectors.providers.linkedin.config_json.sync.dimensions', []);

        $dimensions = array_values(array_unique(array_filter(
            $configured,
            fn (mixed $dimension): bool => is_string($dimension) && trim($dimension) !== ''
        )));

        return $dimensions === [] ? self::DEFAULT_DIMENSIONS : $dimensions;
    }

    /**
     * @param array{start: string, end: string} $dateRange
     */
    private function resourceIndex(ConnectorSyncCursor $cursor, array $dateRange, int $resourceCount): int
    {
        if ($cursor->get('date_range') !== null && $cursor->get('date_range') !== $dateRange) {
            return 0;
        }

        return min(max(0, (int) $cursor->get('resource_index', 0)), max(0, $resourceCount - 1));
    }

    /**
     * @param array{start: string, end: string} $dateRange
     */
    private function start(ConnectorSyncCursor $cursor, array $dateRange, int $resourceIndex): int
    {
        if ($cursor->get('date_range') !== null && $cursor->get('date_range') !== $dateRange) {
            return 0;
        }

        if ((int) $cursor->get('resource_index', 0) !== $resourceIndex) {
            return 0;
        }

        return max(0, (int) $cursor->get('start', 0));
    }

    /**
     * @param list<string> $resources
     * @param array{start: string, end: string} $dateRange
     */
    private function nextCursor(
        array $resources,
        int $resourceIndex,
        bool $resourceHasMore,
        bool $hasNextResource,
        int $nextStart,
        array $dateRange,
    ): ConnectorSyncCursor {
        if ($resourceHasMore) {
            return new ConnectorSyncCursor([
                'resource_index' => $resourceIndex,
                'resource' => $resources[$resourceIndex],
                'start' => $nextStart,
                'date_range' => $dateRange,
            ]);
        }

        if ($hasNextResource) {
            return new ConnectorSyncCursor([
                'resource_index' => $resourceIndex + 1,
                'resource' => $resources[$resourceIndex + 1],
                'start' => 0,
                'date_range' => $dateRange,
            ]);
        }

        return new ConnectorSyncCursor([
            'resource_index' => 0,
            'resource' => $resources[0],
            'start' => 0,
            'date_range' => $dateRange,
            'last_synced_date' => $dateRange['end'],
        ]);
    }

    /**
     * @param array{start: string, end: string} $dateRange
     * @return array<string, mixed>
     */
    private function resourceQuery(ConnectorSyncContext $context, array $dateRange, int $start, int $count): array
    {
        return [
            'q' => 'organizationalEntity',
            'organizationalEntity' => $this->organizationUrn($context),
            'timeIntervals.timeGranularityType' => 'DAY',
            'timeIntervals.timeRange.start' => Carbon::parse($dateRange['start'])->startOfDay()->getTimestamp() * 1000,
            'timeIntervals.timeRange.end' => Carbon::parse($dateRange['end'])->endOfDay()->getTimestamp() * 1000,
            'start' => $start,
            'count' => $count,
        ];
    }

    private function organizationUrn(ConnectorSyncContext $context): string
    {
        $organization = trim((string) (
            data_get($context->plan->dataset->config_json, 'organization_urn')
            ?: data_get($context->plan->dataset->config_json, 'organization')
            ?: $context->plan->dataset->external_dataset_id
        ));

        if ($organization === '') {
            throw new ConnectorFatalSyncException('LinkedIn dataset is missing an organization URN.');
        }

        return str_starts_with($organization, 'urn:li:organization:')
            ? $organization
            : 'urn:li:organization:'.$organization;
    }

    private function resourceUrl(string $resource): string
    {
        return $this->apiBaseUrl().'/'.self::RESOURCE_ENDPOINTS[$resource];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{start: string, end: string} $dateRange
     * @return array<int, array<string, mixed>>
     */
    private function observations(ConnectorSyncContext $context, string $resource, array $rows, array $dateRange): array
    {
        $observations = [];

        foreach ($rows as $row) {
            $periodDate = $this->periodDate($row, $dateRange);
            $periodStart = Carbon::parse($periodDate)->startOfDay();
            $periodEnd = Carbon::parse($periodDate)->endOfDay();
            $dimensions = $this->dimensionValues($context, $row, $periodDate);

            foreach ($this->metricValues($resource, $row) as $metricKey => $metric) {
                $observations[] = [
                    'metric_key' => $metricKey,
                    'metric_value' => $metric['value'],
                    'unit' => $metric['unit'],
                    'period_start' => $periodStart->toDateTimeString(),
                    'period_end' => $periodEnd->toDateTimeString(),
                    'granularity' => MarketingObservation::GRANULARITY_DAILY,
                    'observed_at' => now()->toDateTimeString(),
                    'external_id' => $this->externalId($context, $resource, $metricKey, $dimensions, $periodDate),
                    'dimensions' => $dimensions,
                    'source_metadata' => [
                        'provider' => 'linkedin',
                        'dataset_type' => $resource,
                        'organization' => $this->organizationUrn($context),
                        'source_metric' => $metric['source_metric'],
                    ],
                    'raw_metadata' => MarketingMetadataRedactor::redact([
                        'resource' => $resource,
                        'source_metric' => $metric['source_metric'],
                        'available_metrics' => $metric['available_metrics'],
                        'row' => $row,
                    ]),
                ];
            }
        }

        return $observations;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{start: string, end: string} $dateRange
     * @return array<int, array<string, mixed>>
     */
    private function rawRecords(ConnectorSyncContext $context, string $resource, array $rows, array $dateRange): array
    {
        return collect($rows)
            ->map(function (array $row) use ($context, $resource, $dateRange): array {
                $periodDate = $this->periodDate($row, $dateRange);

                return [
                    'record_type' => $resource,
                    'external_record_id' => $this->externalId(
                        $context,
                        $resource,
                        'raw',
                        $this->dimensionValues($context, $row, $periodDate),
                        $periodDate,
                    ),
                    'period_start' => Carbon::parse($periodDate)->startOfDay()->toDateTimeString(),
                    'period_end' => Carbon::parse($periodDate)->endOfDay()->toDateTimeString(),
                    'observed_at' => now()->toDateTimeString(),
                    'payload' => $row,
                    'metadata' => [
                        'provider' => 'linkedin',
                        'resource' => $resource,
                        'organization' => $this->organizationUrn($context),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, array{value: float, unit: string, source_metric: string, available_metrics: list<string>}>
     */
    private function metricValues(string $resource, array $row): array
    {
        $definitions = match ($resource) {
            'follower_statistics' => self::FOLLOWER_METRICS,
            default => self::SHARE_METRICS,
        };

        $metrics = [];

        foreach ($definitions as $metricKey => $definition) {
            $metric = $this->firstNumericMetric($row, (array) $definition['paths']);

            if ($metric === null) {
                continue;
            }

            $metrics[$metricKey] = [
                'value' => (float) $metric['value'],
                'unit' => (string) $definition['unit'],
                'source_metric' => $metric['path'],
                'available_metrics' => $this->availableMetricPaths($row, (array) $definition['paths']),
            ];
        }

        return $metrics;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $paths
     * @return array{path: string, value: mixed}|null
     */
    private function firstNumericMetric(array $row, array $paths): ?array
    {
        foreach ($paths as $path) {
            $value = data_get($row, $path);

            if (is_numeric($value)) {
                return ['path' => $path, 'value' => $value];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $paths
     * @return list<string>
     */
    private function availableMetricPaths(array $row, array $paths): array
    {
        return array_values(array_filter($paths, fn (string $path): bool => data_get($row, $path) !== null));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function dimensionValues(ConnectorSyncContext $context, array $row, string $periodDate): array
    {
        $values = [
            'date' => $periodDate,
            'organization' => $this->organizationUrn($context),
            'post' => $this->firstString($row, ['post', 'share', 'ugcPost', 'activity', 'entity', 'contentEntity']),
            'mediaType' => $this->firstString($row, ['mediaType', 'shareMediaCategory', 'media_category', 'content.mediaType']),
            'campaign' => $this->firstString($row, ['campaign', 'campaignReference', 'campaignUrn', 'campaign_urn', 'content.campaign']),
            'content' => $this->firstString($row, ['content', 'contentReference', 'contentUrn', 'content_urn', 'landingPage', 'content.reference']),
        ];

        $allowed = array_flip($this->dimensions($context));

        return collect($values)
            ->filter(fn (?string $value, string $key): bool => isset($allowed[$key]) && trim((string) $value) !== '')
            ->map(fn (?string $value): string => (string) $value)
            ->all();
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $paths
     */
    private function firstString(array $row, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($row, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{start: string, end: string} $dateRange
     */
    private function periodDate(array $row, array $dateRange): string
    {
        $date = $this->firstString($row, ['date', 'day']);

        if ($date !== null) {
            return Carbon::parse($date)->toDateString();
        }

        $start = data_get($row, 'timeRange.start');

        if (is_numeric($start)) {
            return Carbon::createFromTimestamp((int) floor(((int) $start) / 1000))->toDateString();
        }

        return $dateRange['start'];
    }

    /**
     * @param array<string, string> $dimensions
     */
    private function externalId(
        ConnectorSyncContext $context,
        string $resource,
        string $metricKey,
        array $dimensions,
        string $periodDate,
    ): string {
        ksort($dimensions);

        return 'linkedin:'.hash('sha256', json_encode([
            'dataset' => $context->plan->dataset->external_dataset_id,
            'resource' => $resource,
            'metric' => $metricKey,
            'date' => $periodDate,
            'dimensions' => $dimensions,
        ], JSON_THROW_ON_ERROR));
    }

    private function hasMore(Response $response, int $start, int $count, int $rowCount): bool
    {
        $total = $response->json('paging.total');

        if (is_numeric($total)) {
            return ($start + $count) < (int) $total;
        }

        return $rowCount >= $count;
    }

    private function throwIfFailed(Response $response, string $resource): void
    {
        if ($response->successful()) {
            return;
        }

        $message = 'LinkedIn '.str_replace('_', ' ', $resource).' request failed with status '.$response->status().'.';

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
            'limit' => $response->header('X-RateLimit-Limit') ?: $response->header('X-RestLi-RateLimit-Limit'),
            'remaining' => $response->header('X-RateLimit-Remaining') ?: $response->header('X-RestLi-RateLimit-Remaining'),
            'reset' => $response->header('X-RateLimit-Reset') ?: $response->header('X-RestLi-RateLimit-Reset'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'X-Restli-Protocol-Version' => '2.0.0',
        ];

        $version = trim((string) config('data_connectors.providers.linkedin.config_json.api.linkedin_version', ''));

        if ($version !== '') {
            $headers['LinkedIn-Version'] = $version;
        }

        return $headers;
    }

    private function pageSize(ConnectorSyncContext $context): int
    {
        $configured = (int) config('data_connectors.providers.linkedin.config_json.api.page_size', 100);

        return max(1, min(1000, (int) ($context->plan->pageSize ?: $configured)));
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) config('data_connectors.providers.linkedin.config_json.api.base_url', 'https://api.linkedin.com/v2'), '/');
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('data_connectors.providers.linkedin.config_json.api.timeout_seconds', 15));
    }
}
