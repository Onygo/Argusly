<?php

namespace App\Services\Integrations\Google;

use App\Models\ContentAsset;
use App\Models\Ga4MetricSnapshot;
use App\Models\Ga4Property;
use App\Services\DomainEventService;
use App\Services\Signals\SignalManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GA4DataService
{
    private const DATA_BASE_URL = 'https://analyticsdata.googleapis.com/v1beta';

    public function __construct(
        private readonly GoogleTokenService $tokens,
        private readonly DomainEventService $events,
        private readonly SignalManager $signals,
    ) {}

    public function sync(Ga4Property $property, int $days = 30): Collection
    {
        $property->loadMissing(['integrationConnection.integration', 'account', 'brand']);
        $connection = $property->integrationConnection;

        if (! $connection) {
            throw new RuntimeException('GA4 property is not linked to a Google integration connection.');
        }

        $connection = $this->tokens->refreshIfPossible($connection);

        if ($connection->status !== 'active' || $this->tokens->isExpired($connection) || blank($connection->access_token)) {
            throw new RuntimeException('Reconnect Google integration before syncing GA4 data.');
        }

        $propertyName = (string) ($property->metadata['property_id'] ?? '');
        $propertyId = (string) ($property->metadata['numeric_property_id'] ?? str($propertyName)->after('properties/'));

        if (blank($propertyId)) {
            throw new RuntimeException('GA4 property ID is missing.');
        }

        $startDate = CarbonImmutable::today()->subDays(max(1, $days) - 1);
        $endDate = CarbonImmutable::today();
        $rows = $this->runReport($connection->access_token, $propertyId, $startDate, $endDate);
        $assets = $this->contentAssetLookup($property);

        $snapshots = $rows->map(fn (array $row) => $this->storeRow($property, $row, $assets));

        $property->forceFill([
            'status' => 'connected',
            'last_synced_at' => now(),
            'metadata' => [
                ...($property->metadata ?? []),
                'last_sync_rows' => $snapshots->count(),
                'last_sync_started_at' => $startDate->toDateString(),
                'last_sync_ended_at' => $endDate->toDateString(),
            ],
        ])->save();

        $this->events->recordForSubject('GA4SyncCompleted', $property->refresh(), null, [
            'ga4_property_id' => $property->id,
            'rows_synced' => $snapshots->count(),
            'started_at' => $startDate->toDateString(),
            'ended_at' => $endDate->toDateString(),
        ]);

        $this->recordTrafficDropSignal($property->refresh(), $days);

        return $snapshots->values();
    }

    private function runReport(string $accessToken, string $propertyId, CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
    {
        try {
            $payload = Http::withToken($accessToken)
                ->acceptJson()
                ->post(self::DATA_BASE_URL."/properties/{$propertyId}:runReport", [
                    'dateRanges' => [[
                        'startDate' => $startDate->toDateString(),
                        'endDate' => $endDate->toDateString(),
                    ]],
                    'dimensions' => collect(['date', 'pagePath', 'country', 'deviceCategory'])
                        ->map(fn (string $name) => ['name' => $name])
                        ->all(),
                    'metrics' => collect(['sessions', 'activeUsers', 'totalUsers', 'screenPageViews', 'engagementRate', 'conversions'])
                        ->map(fn (string $name) => ['name' => $name])
                        ->all(),
                    'limit' => 100000,
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('GA4 Data API sync failed. Please try again.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('GA4 Data API returned an invalid response.');
        }

        $dimensionHeaders = collect($payload['dimensionHeaders'] ?? [])->pluck('name')->values();
        $metricHeaders = collect($payload['metricHeaders'] ?? [])->pluck('name')->values();

        return collect($payload['rows'] ?? [])
            ->map(fn (array $row) => [
                'dimensions' => $this->valuesByHeader($dimensionHeaders, $row['dimensionValues'] ?? []),
                'metrics' => $this->valuesByHeader($metricHeaders, $row['metricValues'] ?? []),
                'raw' => $row,
            ]);
    }

    private function storeRow(Ga4Property $property, array $row, Collection $assets): Ga4MetricSnapshot
    {
        $dimensions = $row['dimensions'];
        $metrics = $row['metrics'];
        $pagePath = $this->normalizePath($dimensions['pagePath'] ?? null);
        $date = CarbonImmutable::createFromFormat('Ymd', (string) ($dimensions['date'] ?? now()->format('Ymd')))->toDateString();
        $asset = $assets->get($pagePath);

        $snapshot = Ga4MetricSnapshot::query()
            ->where('ga4_property_id', $property->id)
            ->whereDate('date', $date)
            ->where('page_path', $pagePath)
            ->when(
                $asset,
                fn ($query) => $query->where('content_asset_id', $asset->id),
                fn ($query) => $query->whereNull('content_asset_id'),
            )
            ->first();

        $attributes = [
            'account_id' => $property->account_id,
            'brand_id' => $property->brand_id,
            'ga4_property_id' => $property->id,
            'content_asset_id' => $asset?->id,
            'page_path' => $pagePath,
            'date' => $date,
            'sessions' => $this->metricInt($metrics, 'sessions'),
            'users' => $this->metricInt($metrics, 'activeUsers') ?? $this->metricInt($metrics, 'totalUsers'),
            'pageviews' => $this->metricInt($metrics, 'screenPageViews'),
            'engagement_rate' => $this->metricDecimal($metrics, 'engagementRate'),
            'conversions' => $this->metricInt($metrics, 'conversions'),
            'metadata' => [
                'page_path' => $pagePath,
                'country' => $dimensions['country'] ?? null,
                'device_category' => $dimensions['deviceCategory'] ?? null,
                'matched_content_asset_id' => $asset?->id,
                'raw_dimensions' => $dimensions,
                'raw_metrics' => $metrics,
            ],
        ];

        if ($snapshot) {
            $snapshot->update($attributes);

            return $snapshot->refresh();
        }

        return Ga4MetricSnapshot::query()->create($attributes);
    }

    private function contentAssetLookup(Ga4Property $property): Collection
    {
        return ContentAsset::query()
            ->where('account_id', $property->account_id)
            ->where('brand_id', $property->brand_id)
            ->where(function ($query): void {
                $query->whereNotNull('canonical_url')
                    ->orWhereNotNull('source_url');
            })
            ->get()
            ->flatMap(function (ContentAsset $asset): array {
                return collect([$asset->canonical_url, $asset->source_url])
                    ->filter()
                    ->mapWithKeys(fn (string $url) => [$this->normalizePath($url) => $asset])
                    ->all();
            });
    }

    private function recordTrafficDropSignal(Ga4Property $property, int $days): void
    {
        $window = max(1, $days);
        $currentStart = now()->subDays($window - 1)->startOfDay();
        $previousStart = now()->subDays(($window * 2) - 1)->startOfDay();
        $previousEnd = now()->subDays($window)->endOfDay();

        $current = (int) Ga4MetricSnapshot::query()
            ->where('ga4_property_id', $property->id)
            ->whereBetween('date', [$currentStart->toDateString(), now()->toDateString()])
            ->sum('sessions');

        $previous = (int) Ga4MetricSnapshot::query()
            ->where('ga4_property_id', $property->id)
            ->whereBetween('date', [$previousStart->toDateString(), $previousEnd->toDateString()])
            ->sum('sessions');

        if ($previous < 50 || $current > ($previous * 0.6)) {
            return;
        }

        $dropPercent = round((1 - ($current / max(1, $previous))) * 100);

        $this->signals->record($property->account, [
            'source' => 'ga4_data_sync',
            'type' => 'integration_event',
            'category' => 'integration',
            'priority' => $dropPercent >= 60 ? 'critical' : 'high',
            'dedupe_key' => "ga4-traffic-drop:{$property->id}",
            'title' => 'GA4 traffic dropped strongly',
            'summary' => "Sessions dropped {$dropPercent}% for {$property->display_name}.",
            'impact_score' => min(100, 50 + $dropPercent),
            'confidence_score' => 90,
            'recommended_action' => 'Review recently published or refreshed content and compare acquisition changes.',
            'payload' => [
                'ga4_property_id' => $property->id,
                'current_sessions' => $current,
                'previous_sessions' => $previous,
                'drop_percent' => $dropPercent,
            ],
        ], $property->brand, false);
    }

    private function valuesByHeader(Collection $headers, array $values): array
    {
        return $headers
            ->mapWithKeys(fn (string $header, int $index) => [$header => $values[$index]['value'] ?? null])
            ->all();
    }

    private function metricInt(array $metrics, string $name): ?int
    {
        return isset($metrics[$name]) && $metrics[$name] !== '' ? (int) round((float) $metrics[$name]) : null;
    }

    private function metricDecimal(array $metrics, string $name): ?float
    {
        return isset($metrics[$name]) && $metrics[$name] !== '' ? round((float) $metrics[$name] * 100, 2) : null;
    }

    private function normalizePath(?string $urlOrPath): ?string
    {
        if (blank($urlOrPath)) {
            return null;
        }

        $path = parse_url($urlOrPath, PHP_URL_PATH) ?: $urlOrPath;
        $path = '/'.ltrim($path, '/');

        return rtrim($path, '/') ?: '/';
    }
}
