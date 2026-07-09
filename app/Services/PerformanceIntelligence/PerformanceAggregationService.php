<?php

namespace App\Services\PerformanceIntelligence;

use App\Models\ClientSite;
use App\Models\MarketingObservation;
use App\Models\MonitoredPage;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PerformanceAggregationService
{
    private const PAGE_DIMENSIONS = [
        'page',
        'page_path',
        'pagepath',
        'landing_page',
        'landingpage',
        'url',
        'page_url',
        'pageurl',
        'canonical_url',
        'content_url',
        'path',
    ];

    private const CHANNEL_DIMENSIONS = [
        'channel',
        'defaultchannelgroup',
        'default_channel_group',
        'sessiondefaultchannelgroup',
        'session_default_channel_group',
        'sessionmedium',
        'session_medium',
        'medium',
        'utm_medium',
        'source_medium',
    ];

    private const TOPIC_DIMENSIONS = [
        'topic',
        'topic_key',
        'topic_name',
        'query',
        'search_query',
        'keyword',
    ];

    private const ENTITY_DIMENSIONS = [
        'entity',
        'entity_key',
        'entity_name',
        'brand',
        'competitor',
    ];

    private const CONTENT_DIMENSIONS = [
        'content',
        'content_id',
        'content_key',
        'post',
        'article',
        'asset',
    ];

    private const MARKET_PACK_DIMENSIONS = [
        'market_pack',
        'marketpack',
        'market_pack_key',
        'market',
    ];

    /**
     * @return Collection<int, MarketingObservation>
     */
    public function observations(Workspace $workspace, ?ClientSite $clientSite, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return MarketingObservation::query()
            ->with(['dimensions', 'attributions', 'metricDefinition'])
            ->where('workspace_id', $workspace->id)
            ->when($clientSite, fn (Builder $query): Builder => $query->where('client_site_id', $clientSite->id))
            ->where('period_start', '>=', $from)
            ->where('period_start', '<=', $to)
            ->orderBy('period_start')
            ->get();
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     * @return Collection<int, array<string, mixed>>
     */
    public function classify(Collection $observations, Workspace $workspace, ?ClientSite $clientSite): Collection
    {
        $pages = $this->candidatePages($workspace, $clientSite, $observations);

        return $observations
            ->map(function (MarketingObservation $observation) use ($pages, $clientSite): array {
                $dimensions = $this->dimensionMap($observation);
                $page = $this->resolvePage($observation, $pages, $clientSite, $dimensions);

                return [
                    'observation' => $observation,
                    'dimensions' => $dimensions,
                    'page' => $page,
                    'channel' => $this->resolveChannel($observation, $dimensions),
                    'topics' => $this->resolveTopics($observation, $dimensions, $page),
                    'entities' => $this->resolveEntities($observation, $dimensions, $page),
                    'content' => $this->resolveContentReferences($observation, $dimensions),
                    'market_packs' => $this->resolveMarketPacks($observation, $dimensions, $page),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $classified
     * @return Collection<string, array{item: MonitoredPage, records: Collection<int, array<string, mixed>>}>
     */
    public function pageGroups(Collection $classified): Collection
    {
        return $classified
            ->filter(fn (array $record): bool => $record['page'] instanceof MonitoredPage)
            ->groupBy(fn (array $record): string => (string) $record['page']->id)
            ->map(fn (Collection $records): array => [
                'item' => $records->first()['page'],
                'records' => $records->values(),
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $classified
     * @return Collection<string, array{item: array<string, string>, records: Collection<int, array<string, mixed>>}>
     */
    public function channelGroups(Collection $classified): Collection
    {
        return $classified
            ->groupBy(fn (array $record): string => (string) $record['channel']['key'])
            ->map(fn (Collection $records): array => [
                'item' => $records->first()['channel'],
                'records' => $records->values(),
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $classified
     * @return Collection<string, array{item: array<string, string|null>, records: Collection<int, array<string, mixed>>}>
     */
    public function topicGroups(Collection $classified): Collection
    {
        return $this->multiValueGroups($classified, 'topics');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $classified
     * @return Collection<string, array{item: array<string, string|null>, records: Collection<int, array<string, mixed>>}>
     */
    public function marketPackGroups(Collection $classified): Collection
    {
        return $this->multiValueGroups($classified, 'market_packs');
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     * @return array<string, mixed>
     */
    public function metricSummaries(Collection $observations): array
    {
        return $this->uniqueObservations($observations)
            ->groupBy('metric_key')
            ->map(fn (Collection $metricObservations, string $metricKey): array => $this->metricSummary($metricKey, $metricObservations))
            ->sortKeys()
            ->all();
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     */
    public function aggregateMetricValue(Collection $observations): ?float
    {
        $observations = $this->uniqueObservations($observations);

        if ($observations->isEmpty()) {
            return null;
        }

        $aggregation = $this->aggregationFor($observations->first());
        $values = $observations
            ->map(fn (MarketingObservation $observation): float => (float) $observation->metric_value)
            ->values();

        return match ($aggregation) {
            'average' => round((float) $values->avg(), 6),
            'latest' => round((float) $observations->sortBy('period_start')->last()->metric_value, 6),
            default => round((float) $values->sum(), 6),
        };
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     */
    public function confidenceFor(Collection $observations): float
    {
        $observations = $this->uniqueObservations($observations);

        if ($observations->isEmpty()) {
            return 0.0;
        }

        $scores = $observations->map(function (MarketingObservation $observation): float {
            $confidence = $observation->confidence_score === null ? 0.75 : (float) $observation->confidence_score;
            $quality = $observation->quality_score === null ? 0.75 : (float) $observation->quality_score;

            return ($confidence + $quality) / 2;
        });

        return round(max(0.0, min(1.0, (float) $scores->avg())), 4);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return Collection<int, MarketingObservation>
     */
    public function observationsFromRecords(Collection $records): Collection
    {
        return $this->uniqueObservations($records->pluck('observation'));
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     * @return array<int, string>
     */
    public function observationIds(Collection $observations): array
    {
        return $this->uniqueObservations($observations)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array<int, string>
     */
    public function pageIdsFromRecords(Collection $records): array
    {
        return $records
            ->pluck('page')
            ->filter(fn (mixed $page): bool => $page instanceof MonitoredPage)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     * @return Collection<int, MarketingObservation>
     */
    public function uniqueObservations(Collection $observations): Collection
    {
        return $observations
            ->filter(fn (mixed $observation): bool => $observation instanceof MarketingObservation)
            ->unique(fn (MarketingObservation $observation): string => (string) $observation->id)
            ->values();
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     * @return Collection<int, MonitoredPage>
     */
    private function candidatePages(Workspace $workspace, ?ClientSite $clientSite, Collection $observations): Collection
    {
        $candidates = $this->pageCandidateValues($observations, $clientSite);

        return MonitoredPage::query()
            ->with(['topics', 'entities', 'marketPackMatches'])
            ->where('workspace_id', $workspace->id)
            ->when($clientSite, fn (Builder $query): Builder => $query->where('client_site_id', $clientSite->id))
            ->when($candidates['ids'] !== [] || $candidates['urls'] !== [] || $candidates['paths'] !== [], function (Builder $query) use ($candidates): void {
                $query->where(function (Builder $query) use ($candidates): void {
                    if ($candidates['ids'] !== []) {
                        $query->orWhereIn('id', $candidates['ids']);
                    }

                    if ($candidates['urls'] !== []) {
                        $hashes = array_map(fn (string $url): string => hash('sha256', $url), $candidates['urls']);
                        $query
                            ->orWhereIn('canonical_url', $candidates['urls'])
                            ->orWhereIn('final_url', $candidates['urls'])
                            ->orWhereIn('first_seen_url', $candidates['urls'])
                            ->orWhereIn('canonical_url_hash', $hashes)
                            ->orWhereIn('final_url_hash', $hashes)
                            ->orWhereIn('first_seen_url_hash', $hashes);
                    }

                    if ($candidates['paths'] !== []) {
                        $query->orWhereIn('path', $candidates['paths']);
                    }
                });
            })
            ->get();
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     * @return array{ids: array<int, string>, urls: array<int, string>, paths: array<int, string>}
     */
    private function pageCandidateValues(Collection $observations, ?ClientSite $clientSite): array
    {
        $ids = [];
        $urls = [];
        $paths = [];

        foreach ($observations as $observation) {
            foreach ($this->observationPageValues($observation, $this->dimensionMap($observation)) as $value) {
                if ($this->looksLikeUuid($value)) {
                    $ids[] = $value;
                }

                $url = $this->normalizeUrl($value, $clientSite);
                if ($url !== null) {
                    $urls[] = $url;
                }

                $path = $this->normalizePath($value);
                if ($path !== null) {
                    $paths[] = $path;
                }
            }
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'urls' => array_values(array_unique($urls)),
            'paths' => array_values(array_unique($paths)),
        ];
    }

    /**
     * @param  Collection<int, MonitoredPage>  $pages
     * @param  array<string, string>  $dimensions
     */
    private function resolvePage(MarketingObservation $observation, Collection $pages, ?ClientSite $clientSite, array $dimensions): ?MonitoredPage
    {
        $values = $this->observationPageValues($observation, $dimensions);

        foreach ($values as $value) {
            $id = $this->looksLikeUuid($value) ? $value : null;
            $url = $this->normalizeUrl($value, $clientSite);
            $path = $this->normalizePath($value);

            $page = $pages->first(function (MonitoredPage $page) use ($id, $url, $path): bool {
                if ($id !== null && (string) $page->id === $id) {
                    return true;
                }

                $pageUrls = array_filter([
                    $this->normalizeUrl((string) $page->canonical_url),
                    $this->normalizeUrl((string) $page->final_url),
                    $this->normalizeUrl((string) $page->first_seen_url),
                ]);

                if ($url !== null && in_array($url, $pageUrls, true)) {
                    return true;
                }

                return $path !== null && $this->normalizePath((string) $page->path) === $path;
            });

            if ($page instanceof MonitoredPage) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return array<int, string>
     */
    private function observationPageValues(MarketingObservation $observation, array $dimensions): array
    {
        $values = [];

        foreach (self::PAGE_DIMENSIONS as $key) {
            if (isset($dimensions[$key])) {
                $values[] = $dimensions[$key];
            }
        }

        foreach ($observation->attributions as $attribution) {
            $haystack = mb_strtolower(implode('|', array_filter([
                (string) $attribution->attribution_type,
                (string) $attribution->attributed_type,
                (string) $attribution->attribution_key,
            ])));

            if (str_contains($haystack, 'page') || str_contains($haystack, MonitoredPage::class)) {
                foreach ([$attribution->attributed_id, $attribution->attribution_value] as $value) {
                    if (filled($value)) {
                        $values[] = (string) $value;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($values, fn (string $value): bool => trim($value) !== '')));
    }

    /**
     * @return array<string, string>
     */
    private function dimensionMap(MarketingObservation $observation): array
    {
        $map = [];

        foreach ($observation->dimensions as $dimension) {
            $key = $this->normalizeKey((string) $dimension->dimension_key);
            $map[$key] = (string) $dimension->dimension_value;
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return array{key: string, name: string}
     */
    private function resolveChannel(MarketingObservation $observation, array $dimensions): array
    {
        $raw = null;

        foreach (self::CHANNEL_DIMENSIONS as $key) {
            if (isset($dimensions[$key]) && trim($dimensions[$key]) !== '') {
                $raw = $dimensions[$key];
                break;
            }
        }

        $raw ??= $this->inferChannelFromMetric($observation);
        $normalized = mb_strtolower(trim((string) $raw));

        $channel = match (true) {
            $normalized === '' => ['key' => 'unknown', 'name' => 'Unknown'],
            str_contains($normalized, 'organic search') || in_array($normalized, ['organic', 'seo'], true) => ['key' => 'organic_search', 'name' => 'Organic Search'],
            str_contains($normalized, 'paid search') || in_array($normalized, ['cpc', 'ppc', 'paid'], true) => ['key' => 'paid_search', 'name' => 'Paid Search'],
            str_contains($normalized, 'social') || str_contains($normalized, 'linkedin') || str_contains($normalized, 'facebook') || str_contains($normalized, 'instagram') => ['key' => 'social', 'name' => 'Social'],
            str_contains($normalized, 'referral') => ['key' => 'referral', 'name' => 'Referral'],
            str_contains($normalized, 'direct') => ['key' => 'direct', 'name' => 'Direct'],
            str_contains($normalized, 'email') || str_contains($normalized, 'newsletter') => ['key' => 'email', 'name' => 'Email'],
            str_contains($normalized, 'ai') || str_contains($normalized, 'geo') || str_contains($normalized, 'llm') => ['key' => 'ai_visibility', 'name' => 'AI Visibility'],
            str_contains($normalized, 'earned') || str_contains($normalized, 'pr') || str_contains($normalized, 'media') => ['key' => 'earned_media', 'name' => 'Earned Media'],
            default => ['key' => Str::slug($normalized, '_'), 'name' => Str::headline($normalized)],
        };

        return $channel['key'] === '' ? ['key' => 'unknown', 'name' => 'Unknown'] : $channel;
    }

    private function inferChannelFromMetric(MarketingObservation $observation): string
    {
        $metric = mb_strtolower((string) $observation->metric_key);

        if (str_contains($metric, 'geo') || str_contains($metric, 'ai_visibility') || str_contains($metric, 'llm')) {
            return 'AI Visibility';
        }

        if (str_contains($metric, 'pr') || str_contains($metric, 'earned_media')) {
            return 'Earned Media';
        }

        return '';
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return array<int, array<string, string|null>>
     */
    private function resolveTopics(MarketingObservation $observation, array $dimensions, ?MonitoredPage $page): array
    {
        $topics = [];

        foreach (self::TOPIC_DIMENSIONS as $key) {
            if (isset($dimensions[$key]) && trim($dimensions[$key]) !== '') {
                $topics[] = [
                    'key' => $this->semanticKey($dimensions[$key]),
                    'name' => $dimensions[$key],
                    'source' => 'dimension',
                ];
            }
        }

        foreach ($observation->attributions as $attribution) {
            $haystack = mb_strtolower(implode('|', array_filter([
                (string) $attribution->attribution_type,
                (string) $attribution->attributed_type,
                (string) $attribution->attribution_key,
            ])));

            if (str_contains($haystack, 'topic')) {
                $value = (string) ($attribution->attribution_value ?: $attribution->attributed_id);
                if ($value !== '') {
                    $topics[] = ['key' => $this->semanticKey($value), 'name' => $value, 'source' => 'attribution'];
                }
            }
        }

        if ($page instanceof MonitoredPage) {
            foreach ($page->topics as $topic) {
                $topics[] = [
                    'key' => (string) ($topic->topic_key ?: $this->semanticKey((string) $topic->topic_name)),
                    'name' => (string) ($topic->topic_name ?: $topic->topic_key),
                    'source' => 'page_topic',
                ];
            }
        }

        return $this->uniqueSemanticItems($topics);
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return array<int, array<string, string|null>>
     */
    private function resolveEntities(MarketingObservation $observation, array $dimensions, ?MonitoredPage $page): array
    {
        $entities = [];

        foreach (self::ENTITY_DIMENSIONS as $key) {
            if (isset($dimensions[$key]) && trim($dimensions[$key]) !== '') {
                $entities[] = [
                    'key' => $this->semanticKey($dimensions[$key]),
                    'name' => $dimensions[$key],
                    'type' => $key,
                    'source' => 'dimension',
                ];
            }
        }

        foreach ($observation->attributions as $attribution) {
            $haystack = mb_strtolower(implode('|', array_filter([
                (string) $attribution->attribution_type,
                (string) $attribution->attributed_type,
                (string) $attribution->attribution_key,
            ])));

            if (str_contains($haystack, 'entity') || str_contains($haystack, 'competitor') || str_contains($haystack, 'brand')) {
                $value = (string) ($attribution->attribution_value ?: $attribution->attributed_id);
                if ($value !== '') {
                    $entities[] = ['key' => $this->semanticKey($value), 'name' => $value, 'type' => null, 'source' => 'attribution'];
                }
            }
        }

        if ($page instanceof MonitoredPage) {
            foreach ($page->entities as $entity) {
                $entities[] = [
                    'key' => (string) ($entity->entity_key ?: $this->semanticKey((string) $entity->entity_name)),
                    'name' => (string) ($entity->entity_name ?: $entity->entity_key),
                    'type' => (string) $entity->entity_type,
                    'source' => 'page_entity',
                ];
            }
        }

        return $this->uniqueSemanticItems($entities);
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return array<int, array<string, string|null>>
     */
    private function resolveContentReferences(MarketingObservation $observation, array $dimensions): array
    {
        $content = [];

        foreach (self::CONTENT_DIMENSIONS as $key) {
            if (isset($dimensions[$key]) && trim($dimensions[$key]) !== '') {
                $content[] = [
                    'key' => $this->semanticKey($dimensions[$key]),
                    'name' => $dimensions[$key],
                    'source' => 'dimension',
                ];
            }
        }

        foreach ($observation->attributions as $attribution) {
            $haystack = mb_strtolower(implode('|', array_filter([
                (string) $attribution->attribution_type,
                (string) $attribution->attributed_type,
                (string) $attribution->attribution_key,
            ])));

            if (str_contains($haystack, 'content')) {
                $value = (string) ($attribution->attribution_value ?: $attribution->attributed_id);
                if ($value !== '') {
                    $content[] = ['key' => $this->semanticKey($value), 'name' => $value, 'source' => 'attribution'];
                }
            }
        }

        return $this->uniqueSemanticItems($content);
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return array<int, array<string, string|null>>
     */
    private function resolveMarketPacks(MarketingObservation $observation, array $dimensions, ?MonitoredPage $page): array
    {
        $packs = [];

        foreach (self::MARKET_PACK_DIMENSIONS as $key) {
            if (isset($dimensions[$key]) && trim($dimensions[$key]) !== '') {
                $packs[] = [
                    'key' => $this->semanticKey($dimensions[$key]),
                    'name' => $dimensions[$key],
                    'source' => 'dimension',
                ];
            }
        }

        foreach ($observation->attributions as $attribution) {
            $haystack = mb_strtolower(implode('|', array_filter([
                (string) $attribution->attribution_type,
                (string) $attribution->attributed_type,
                (string) $attribution->attribution_key,
            ])));

            if (str_contains($haystack, 'market')) {
                $value = (string) ($attribution->attribution_value ?: $attribution->attributed_id);
                if ($value !== '') {
                    $packs[] = ['key' => $this->semanticKey($value), 'name' => $value, 'source' => 'attribution'];
                }
            }
        }

        if ($page instanceof MonitoredPage) {
            foreach ($page->marketPackMatches as $match) {
                $packs[] = [
                    'key' => (string) ($match->market_pack_key ?: $this->semanticKey((string) $match->market_pack_name)),
                    'name' => (string) ($match->market_pack_name ?: $match->market_pack_key),
                    'source' => 'page_market_pack_match',
                ];
            }
        }

        return $this->uniqueSemanticItems($packs);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $classified
     * @return Collection<string, array{item: array<string, string|null>, records: Collection<int, array<string, mixed>>}>
     */
    private function multiValueGroups(Collection $classified, string $field): Collection
    {
        $groups = [];

        foreach ($classified as $record) {
            foreach ((array) $record[$field] as $item) {
                $key = (string) ($item['key'] ?? '');

                if ($key === '') {
                    continue;
                }

                $groups[$key]['item'] ??= $item;
                $groups[$key]['records'] ??= collect();
                $groups[$key]['records']->push($record);
            }
        }

        return collect($groups)->map(fn (array $group): array => [
            'item' => $group['item'],
            'records' => $group['records']->values(),
        ]);
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     * @return array<string, mixed>
     */
    private function metricSummary(string $metricKey, Collection $observations): array
    {
        $observations = $this->uniqueObservations($observations);
        $first = $observations->first();

        $periodStart = $observations->min('period_start');
        $periodEnd = $observations->max('period_end');

        return [
            'metric_key' => $metricKey,
            'value' => $this->aggregateMetricValue($observations),
            'unit' => $first?->unit,
            'aggregation' => $first instanceof MarketingObservation ? $this->aggregationFor($first) : 'sum',
            'observation_count' => $observations->count(),
            'observation_ids' => $this->observationIds($observations),
            'confidence' => $this->confidenceFor($observations),
            'period_start' => $periodStart instanceof CarbonInterface ? $periodStart->toDateTimeString() : null,
            'period_end' => $periodEnd instanceof CarbonInterface ? $periodEnd->toDateTimeString() : null,
        ];
    }

    private function aggregationFor(MarketingObservation $observation): string
    {
        $definitionAggregation = (string) ($observation->metricDefinition?->aggregation ?? '');
        $metric = mb_strtolower((string) $observation->metric_key);
        $unit = mb_strtolower((string) $observation->unit);

        if (in_array($definitionAggregation, ['average', 'latest'], true)) {
            return $definitionAggregation;
        }

        if (in_array($unit, ['ratio', 'percent', 'percentage', 'seconds', 'rank', 'score'], true)) {
            return 'average';
        }

        foreach (['rate', 'ctr', 'position', 'duration', 'score', 'visibility'] as $averageMetricPart) {
            if (str_contains($metric, $averageMetricPart)) {
                return 'average';
            }
        }

        return 'sum';
    }

    /**
     * @param  array<int, array<string, string|null>>  $items
     * @return array<int, array<string, string|null>>
     */
    private function uniqueSemanticItems(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item): bool => (string) ($item['key'] ?? '') !== '')
            ->unique(fn (array $item): string => (string) $item['key'])
            ->values()
            ->all();
    }

    private function normalizeKey(string $key): string
    {
        return str_replace(['-', ' '], '_', mb_strtolower(trim($key)));
    }

    private function semanticKey(string $value): string
    {
        return Str::slug(mb_strtolower(trim($value)), '_');
    }

    private function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($value));
    }

    private function normalizeUrl(string $value, ?ClientSite $clientSite = null): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! str_contains($value, '://')) {
            $base = trim((string) ($clientSite?->base_url ?: $clientSite?->site_url), '/');

            if ($base === '' || ! str_starts_with($value, '/')) {
                return null;
            }

            $value = $base.$value;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = mb_strtolower((string) $parts['host']);
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return $scheme.'://'.$host.$path;
    }

    private function normalizePath(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '://')) {
            $path = parse_url($value, PHP_URL_PATH);
            $value = is_string($path) ? $path : '';
        }

        if ($value === '' || str_starts_with($value, 'urn:')) {
            return null;
        }

        $path = '/'.ltrim($value, '/');
        $path = strtok($path, '?') ?: $path;

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
