<?php

namespace App\Services\Integrations\Google;

use App\Models\ContentAsset;
use App\Models\SearchConsoleQuerySnapshot;
use App\Models\SearchConsoleSite;
use App\Services\DomainEventService;
use App\Services\Signals\SignalManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SearchConsolePerformanceService
{
    private const API_BASE_URL = 'https://www.googleapis.com/webmasters/v3/sites';

    public function __construct(
        private readonly GoogleTokenService $tokens,
        private readonly DomainEventService $events,
        private readonly SignalManager $signals,
    ) {}

    public function sync(SearchConsoleSite $site, int $days = 30): Collection
    {
        $site->loadMissing(['integrationConnection.integration', 'account', 'brand']);
        $connection = $site->integrationConnection;

        if (! $connection) {
            throw new RuntimeException('Search Console site is not linked to a Google integration connection.');
        }

        $connection = $this->tokens->refreshIfPossible($connection);

        if ($connection->status !== 'active' || $this->tokens->isExpired($connection) || blank($connection->access_token)) {
            throw new RuntimeException('Reconnect Google integration before syncing Search Console data.');
        }

        $startDate = CarbonImmutable::today()->subDays(max(1, $days) - 1);
        $endDate = CarbonImmutable::today();
        $rows = $this->query($connection->access_token, $site->site_url, $startDate, $endDate);
        $assets = $this->contentAssetLookup($site);

        $snapshots = $rows->map(fn (array $row) => $this->storeRow($site, $row, $assets));

        $site->forceFill([
            'status' => 'connected',
            'last_synced_at' => now(),
            'metadata' => [
                ...($site->metadata ?? []),
                'last_sync_rows' => $snapshots->count(),
                'last_sync_started_at' => $startDate->toDateString(),
                'last_sync_ended_at' => $endDate->toDateString(),
            ],
        ])->save();

        $this->events->recordForSubject('SearchConsoleSyncCompleted', $site->refresh(), null, [
            'search_console_site_id' => $site->id,
            'rows_synced' => $snapshots->count(),
            'started_at' => $startDate->toDateString(),
            'ended_at' => $endDate->toDateString(),
        ]);

        $this->recordSignals($site->refresh(), $days);

        return $snapshots->values();
    }

    private function query(string $accessToken, string $siteUrl, CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
    {
        try {
            $payload = Http::withToken($accessToken)
                ->acceptJson()
                ->post(self::API_BASE_URL.'/'.rawurlencode($siteUrl).'/searchAnalytics/query', [
                    'startDate' => $startDate->toDateString(),
                    'endDate' => $endDate->toDateString(),
                    'dimensions' => ['date', 'query', 'page', 'country', 'device'],
                    'rowLimit' => 25000,
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('Search Console performance sync failed. Please try again.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Search Console performance API returned an invalid response.');
        }

        return collect($payload['rows'] ?? [])
            ->map(fn (array $row) => [
                'date' => $row['keys'][0] ?? null,
                'query' => $row['keys'][1] ?? null,
                'page' => $row['keys'][2] ?? null,
                'country' => $row['keys'][3] ?? null,
                'device' => $row['keys'][4] ?? null,
                'clicks' => $row['clicks'] ?? null,
                'impressions' => $row['impressions'] ?? null,
                'ctr' => $row['ctr'] ?? null,
                'position' => $row['position'] ?? null,
                'raw' => $row,
            ])
            ->filter(fn (array $row) => filled($row['date']));
    }

    private function storeRow(SearchConsoleSite $site, array $row, Collection $assets): SearchConsoleQuerySnapshot
    {
        $page = $row['page'] ? (string) $row['page'] : null;
        $asset = $assets->get($this->normalizePath($page));
        $date = CarbonImmutable::parse($row['date'])->toDateString();
        $country = filled($row['country']) ? strtoupper((string) $row['country']) : null;
        $device = filled($row['device']) ? strtolower((string) $row['device']) : null;

        $snapshot = SearchConsoleQuerySnapshot::query()
            ->where('search_console_site_id', $site->id)
            ->whereDate('date', $date)
            ->where('query', $row['query'])
            ->where('page', $page)
            ->when($country, fn ($query) => $query->where('country', $country), fn ($query) => $query->whereNull('country'))
            ->when($device, fn ($query) => $query->where('device', $device), fn ($query) => $query->whereNull('device'))
            ->first();

        $attributes = [
            'account_id' => $site->account_id,
            'brand_id' => $site->brand_id,
            'search_console_site_id' => $site->id,
            'content_asset_id' => $asset?->id,
            'date' => $date,
            'query' => $row['query'],
            'page' => $page,
            'country' => $country,
            'device' => $device,
            'clicks' => $row['clicks'] !== null ? (int) round((float) $row['clicks']) : null,
            'impressions' => $row['impressions'] !== null ? (int) round((float) $row['impressions']) : null,
            'ctr' => $row['ctr'] !== null ? round((float) $row['ctr'], 4) : null,
            'position' => $row['position'] !== null ? round((float) $row['position'], 2) : null,
            'metadata' => [
                'matched_content_asset_id' => $asset?->id,
                'raw_row' => $row['raw'],
            ],
        ];

        if ($snapshot) {
            $snapshot->update($attributes);

            return $snapshot->refresh();
        }

        return SearchConsoleQuerySnapshot::query()->create($attributes);
    }

    private function contentAssetLookup(SearchConsoleSite $site): Collection
    {
        return ContentAsset::query()
            ->where('account_id', $site->account_id)
            ->where('brand_id', $site->brand_id)
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

    private function recordSignals(SearchConsoleSite $site, int $days): void
    {
        $window = max(1, $days);
        $currentStart = now()->subDays($window - 1)->toDateString();
        $previousStart = now()->subDays(($window * 2) - 1)->toDateString();
        $previousEnd = now()->subDays($window)->toDateString();

        $current = $this->totals($site, $currentStart, now()->toDateString());
        $previous = $this->totals($site, $previousStart, $previousEnd);

        if ($previous['impressions'] >= 100 && $current['impressions'] <= ($previous['impressions'] * 0.6)) {
            $this->recordSignal($site, 'search-console-impression-drop', 'Search impressions dropped strongly', 'high', [
                'current_impressions' => $current['impressions'],
                'previous_impressions' => $previous['impressions'],
            ]);
        }

        if ($previous['clicks'] >= 25 && $current['clicks'] <= ($previous['clicks'] * 0.6)) {
            $this->recordSignal($site, 'search-console-click-drop', 'Search clicks dropped strongly', 'high', [
                'current_clicks' => $current['clicks'],
                'previous_clicks' => $previous['clicks'],
            ]);
        }

        if ($current['impressions'] >= 500 && $current['ctr'] !== null && $current['ctr'] < 0.01) {
            $this->recordSignal($site, 'search-console-low-ctr', 'High impressions with low CTR', 'medium', [
                'current_impressions' => $current['impressions'],
                'current_ctr' => $current['ctr'],
            ]);
        }

        if ($previous['position'] !== null && $current['position'] !== null && ($current['position'] - $previous['position']) >= 5) {
            $this->recordSignal($site, 'search-console-ranking-decline', 'Search ranking declined', 'high', [
                'current_position' => $current['position'],
                'previous_position' => $previous['position'],
            ]);
        }
    }

    /**
     * @return array{clicks: int, impressions: int, ctr: float|null, position: float|null}
     */
    private function totals(SearchConsoleSite $site, string $startDate, string $endDate): array
    {
        $row = SearchConsoleQuerySnapshot::query()
            ->where('search_console_site_id', $site->id)
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks_total')
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions_total')
            ->selectRaw('AVG(ctr) as ctr_average')
            ->selectRaw('AVG(position) as position_average')
            ->first();

        return [
            'clicks' => (int) ($row?->clicks_total ?? 0),
            'impressions' => (int) ($row?->impressions_total ?? 0),
            'ctr' => $row?->ctr_average !== null ? (float) $row->ctr_average : null,
            'position' => $row?->position_average !== null ? (float) $row->position_average : null,
        ];
    }

    private function recordSignal(SearchConsoleSite $site, string $key, string $title, string $priority, array $payload): void
    {
        $this->signals->record($site->account, [
            'source' => 'search_console_sync',
            'type' => 'integration_event',
            'category' => 'integration',
            'priority' => $priority,
            'dedupe_key' => "{$key}:{$site->id}",
            'title' => $title,
            'summary' => $title.' for '.$site->site_url.'.',
            'impact_score' => $priority === 'high' ? 80 : 62,
            'confidence_score' => 90,
            'recommended_action' => 'Review affected search pages, snippets and recent content changes.',
            'payload' => [
                'search_console_site_id' => $site->id,
                ...$payload,
            ],
        ], $site->brand, false);
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
