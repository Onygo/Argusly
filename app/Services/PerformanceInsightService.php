<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\ContentLifecycleScore;
use App\Models\Ga4MetricSnapshot;
use App\Models\PerformanceInsight;
use App\Models\Recommendation;
use App\Models\SearchConsoleQuerySnapshot;
use App\Models\VisibilityProviderRun;
use App\Services\Signals\SignalManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PerformanceInsightService
{
    /**
     * @return Collection<int, PerformanceInsight>
     */
    public function generateForTenant(Account $account, Brand $brand): Collection
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Performance insight brand must belong to the account.');
        }

        return collect()
            ->merge($this->trafficDrops($account, $brand))
            ->merge($this->searchDropsAndCtrOpportunities($account, $brand))
            ->merge($this->socialGaps($account, $brand))
            ->merge($this->translationGaps($account, $brand))
            ->merge($this->visibilityGaps($account, $brand))
            ->merge($this->contentDecay($account, $brand))
            ->merge($this->campaignUnderperformance($account, $brand))
            ->map(fn (array $attributes) => $this->store($account, $brand, $attributes))
            ->values();
    }

    /**
     * @return Collection<int, PerformanceInsight>
     */
    public function openForTenant(Account $account, ?Brand $brand = null, int $limit = 20): Collection
    {
        return PerformanceInsight::query()
            ->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $query) => $query->where('brand_id', $brand->id))
            ->open()
            ->latest('detected_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trafficDrops(Account $account, Brand $brand): array
    {
        return ContentAsset::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->whereHas('ga4MetricSnapshots')
            ->get()
            ->map(function (ContentAsset $asset): ?array {
                $current = $this->sumGa4Sessions($asset, 14, 0);
                $previous = $this->sumGa4Sessions($asset, 28, 15);

                if ($previous < 50 || $current > ($previous * 0.7)) {
                    return null;
                }

                $dropPercent = (int) round((1 - ($current / max(1, $previous))) * 100);

                return [
                    'type' => 'traffic_drop',
                    'content_asset_id' => $asset->id,
                    'title' => 'Traffic dropped for '.$asset->title,
                    'summary' => "Sessions dropped {$dropPercent}% compared with the previous period.",
                    'severity' => $this->severityForDrop($dropPercent),
                    'impact_score' => min(100, max(50, $dropPercent + 30)),
                    'payload' => [
                        'current_sessions' => $current,
                        'previous_sessions' => $previous,
                        'drop_percent' => $dropPercent,
                        'recommendation' => 'Refresh the page, validate distribution and check whether search demand shifted.',
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchDropsAndCtrOpportunities(Account $account, Brand $brand): array
    {
        $keys = SearchConsoleQuerySnapshot::query()
            ->select('content_asset_id', 'query')
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->whereNotNull('query')
            ->groupBy('content_asset_id', 'query')
            ->get();

        return $keys->flatMap(function (SearchConsoleQuerySnapshot $key): array {
            $current = $this->searchAggregate($key, 14, 0);
            $previous = $this->searchAggregate($key, 28, 15);
            $insights = [];

            if ($previous['position'] !== null && $current['position'] !== null && $current['position'] >= $previous['position'] + 3) {
                $delta = round($current['position'] - $previous['position'], 2);
                $insights[] = [
                    'type' => 'ranking_drop',
                    'content_asset_id' => $key->content_asset_id,
                    'title' => 'Ranking dropped for '.$key->query,
                    'summary' => "Average position declined by {$delta} places.",
                    'severity' => $delta >= 8 ? 'high' : 'medium',
                    'impact_score' => min(100, 55 + (int) round($delta * 5)),
                    'payload' => [
                        'query' => $key->query,
                        'current_position' => $current['position'],
                        'previous_position' => $previous['position'],
                        'recommendation' => 'Refresh the ranking page and compare competing results for intent changes.',
                    ],
                ];
            }

            if (($current['impressions'] ?? 0) >= 1000 && ($current['ctr'] ?? 1) < 0.02 && ($current['position'] ?? 100) <= 10) {
                $insights[] = [
                    'type' => 'ctr_opportunity',
                    'content_asset_id' => $key->content_asset_id,
                    'title' => 'CTR opportunity for '.$key->query,
                    'summary' => 'The query has strong impressions and a top-ten average position, but CTR is below 2%.',
                    'severity' => 'medium',
                    'impact_score' => min(100, 60 + (int) floor(($current['impressions'] ?? 0) / 1000) * 5),
                    'payload' => [
                        'query' => $key->query,
                        'impressions' => $current['impressions'],
                        'ctr' => $current['ctr'],
                        'position' => $current['position'],
                        'recommendation' => 'Test a clearer title, meta description and answer-led opening for this query.',
                    ],
                ];
            }

            return $insights;
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function socialGaps(Account $account, Brand $brand): array
    {
        return ContentAsset::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->published()
            ->whereHas('publishingActions', fn (Builder $query) => $query->where('status', 'completed'))
            ->whereDoesntHave('socialPosts', fn (Builder $query) => $query->whereIn('status', ['scheduled', 'queued', 'publishing', 'published']))
            ->get()
            ->map(fn (ContentAsset $asset) => [
                'type' => 'social_gap',
                'content_asset_id' => $asset->id,
                'title' => 'Published content has no social distribution',
                'summary' => "{$asset->title} is published but has no active or published social post.",
                'severity' => 'medium',
                'impact_score' => 68,
                'payload' => [
                    'recommendation' => 'Create and schedule a social post that links back to the published asset.',
                ],
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function translationGaps(Account $account, Brand $brand): array
    {
        $enabled = collect($brand->enabled_content_languages ?? [])->filter()->values();

        if ($enabled->count() < 2) {
            return [];
        }

        return ContentLifecycleScore::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->whereNotNull('signals')
            ->latest('scored_at')
            ->get()
            ->unique('content_asset_id')
            ->map(function (ContentLifecycleScore $score) {
                $missing = $score->signals['translation_coverage']['missing_languages'] ?? [];

                if ($missing === []) {
                    return null;
                }

                return [
                    'type' => 'translation_gap',
                    'content_asset_id' => $score->content_asset_id,
                    'title' => 'Content is missing enabled translations',
                    'summary' => 'Missing translations: '.implode(', ', $missing).'.',
                    'severity' => count($missing) >= 2 ? 'high' : 'medium',
                    'impact_score' => min(100, 58 + count($missing) * 12),
                    'payload' => [
                        'missing_languages' => $missing,
                        'recommendation' => 'Translate this asset for the enabled brand languages that are still missing.',
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function visibilityGaps(Account $account, Brand $brand): array
    {
        return VisibilityProviderRun::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('status', 'completed')
            ->where('captured_at', '>=', now()->subDays(30))
            ->with(['citations', 'answerEntities'])
            ->get()
            ->filter(fn (VisibilityProviderRun $run) => (int) ($run->metadata['visibility_score'] ?? 100) < 50
                || $run->citations->isEmpty()
                || $run->answerEntities->contains(fn ($entity) => $entity->entity_type === 'competitor'))
            ->map(fn (VisibilityProviderRun $run) => [
                'type' => 'visibility_gap',
                'title' => 'AI visibility is weak for '.$run->provider,
                'summary' => $this->visibilityGapSummary($run),
                'severity' => ((int) ($run->metadata['visibility_score'] ?? 0)) < 30 ? 'high' : 'medium',
                'impact_score' => 100 - (int) ($run->metadata['visibility_score'] ?? 0),
                'payload' => [
                    'provider_run_id' => $run->id,
                    'provider' => $run->provider,
                    'query' => $run->query,
                    'visibility_score' => $run->metadata['visibility_score'] ?? null,
                    'citation_count' => $run->citations->count(),
                    'competitor_mentions' => $run->answerEntities->where('entity_type', 'competitor')->pluck('entity_name')->values()->all(),
                    'recommendation' => $this->visibilityGapRecommendation($run),
                ],
            ])
            ->values()
            ->all();
    }

    private function visibilityGapSummary(VisibilityProviderRun $run): string
    {
        if ($run->citations->isEmpty()) {
            return 'The latest provider run has no citations for the tracked prompt.';
        }

        if ($run->answerEntities->contains(fn ($entity) => $entity->entity_type === 'competitor')) {
            return 'The latest provider run includes competitor presence for the tracked prompt.';
        }

        return 'The latest provider run scored below 50 for the tracked prompt.';
    }

    private function visibilityGapRecommendation(VisibilityProviderRun $run): string
    {
        if ($run->citations->isEmpty()) {
            return 'Strengthen authoritative cited sources and rerun this visibility prompt.';
        }

        if ($run->answerEntities->contains(fn ($entity) => $entity->entity_type === 'competitor')) {
            return 'Clarify brand positioning against competitors and improve citation depth for this topic.';
        }

        return 'Create or refresh answer-led content for this prompt and strengthen citations.';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contentDecay(Account $account, Brand $brand): array
    {
        return ContentLifecycleScore::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->latest('scored_at')
            ->get()
            ->unique('content_asset_id')
            ->filter(fn (ContentLifecycleScore $score) => in_array($score->status, ['decaying', 'needs_refresh', 'critical'], true) || (int) $score->health_score < 60)
            ->map(fn (ContentLifecycleScore $score) => [
                'type' => 'content_decay',
                'content_asset_id' => $score->content_asset_id,
                'title' => 'Content decay detected',
                'summary' => $score->reason ?: 'The lifecycle score indicates this content needs attention.',
                'severity' => $score->status === 'critical' || (int) $score->health_score < 40 ? 'critical' : 'high',
                'impact_score' => min(100, max(55, 100 - (int) $score->health_score + (int) $score->refresh_priority)),
                'payload' => [
                    'content_lifecycle_score_id' => $score->id,
                    'status' => $score->status,
                    'health_score' => $score->health_score,
                    'refresh_priority' => $score->refresh_priority,
                    'recommendation' => 'Refresh this content using lifecycle signals and rerun performance checks afterward.',
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function campaignUnderperformance(Account $account, Brand $brand): array
    {
        return Campaign::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->whereIn('status', ['active', 'paused'])
            ->with('contentAssets')
            ->get()
            ->map(function (Campaign $campaign): ?array {
                $assetIds = $campaign->contentAssets->pluck('id');

                if ($assetIds->isEmpty()) {
                    return null;
                }

                $current = $this->sumGa4SessionsForAssets($assetIds->all(), 14, 0);
                $previous = $this->sumGa4SessionsForAssets($assetIds->all(), 28, 15);

                if ($previous < 75 || $current > ($previous * 0.75)) {
                    return null;
                }

                $dropPercent = (int) round((1 - ($current / max(1, $previous))) * 100);

                return [
                    'type' => 'campaign_underperformance',
                    'campaign_id' => $campaign->id,
                    'title' => 'Campaign performance is under target',
                    'summary' => "{$campaign->name} traffic dropped {$dropPercent}% across campaign assets.",
                    'severity' => $this->severityForDrop($dropPercent),
                    'impact_score' => min(100, max(60, $dropPercent + 35)),
                    'payload' => [
                        'current_sessions' => $current,
                        'previous_sessions' => $previous,
                        'drop_percent' => $dropPercent,
                        'content_asset_ids' => $assetIds->values()->all(),
                        'recommendation' => 'Review campaign distribution, refresh the weakest assets and schedule follow-up social posts.',
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(Account $account, Brand $brand, array $attributes): PerformanceInsight
    {
        $identity = [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => $attributes['type'],
            'content_asset_id' => $attributes['content_asset_id'] ?? null,
            'campaign_id' => $attributes['campaign_id'] ?? null,
            'resolved_at' => null,
        ];

        $insight = PerformanceInsight::query()->updateOrCreate($identity, [
            ...$attributes,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'detected_at' => now(),
        ]);

        $this->projectSignalAndRecommendation($account, $brand, $insight);

        if ($insight->wasRecentlyCreated) {
            app(DomainEventService::class)->recordForSubject('PerformanceInsightDetected', $insight, null, [
                'performance_insight_id' => $insight->id,
                'performance_insight_type' => $insight->type,
                'title' => $insight->title,
                'summary' => $insight->summary,
                'severity' => $insight->severity,
                'impact_score' => $insight->impact_score,
                'payload' => $insight->payload,
            ], $insight->detected_at);
        }

        return $insight;
    }

    private function projectSignalAndRecommendation(Account $account, Brand $brand, PerformanceInsight $insight): void
    {
        $signal = app(SignalManager::class)->record($account, [
            'source' => 'performance_insights',
            'type' => $this->signalType($insight->type),
            'category' => $this->signalCategory($insight->type),
            'priority' => $this->priority($insight->severity),
            'dedupe_key' => "performance-insight:{$insight->id}",
            'title' => $insight->title,
            'summary' => $insight->summary,
            'impact_score' => $insight->impact_score,
            'confidence_score' => 86,
            'payload' => [
                ...($insight->payload ?? []),
                'performance_insight_id' => $insight->id,
                'performance_insight_type' => $insight->type,
                'content_asset_id' => $insight->content_asset_id,
                'campaign_id' => $insight->campaign_id,
            ],
        ], $brand, false);

        Recommendation::query()->updateOrCreate(
            [
                'account_id' => $account->id,
                'signal_id' => $signal->id,
                'title' => $this->recommendationTitle($insight->type),
            ],
            [
                'brand_id' => $brand->id,
                'summary' => $insight->summary,
                'recommended_action' => $insight->payload['recommendation'] ?? $this->defaultRecommendation($insight->type),
                'action_type' => $this->actionType($insight->type),
                'action_payload' => $this->actionPayload($insight),
                'impact_score' => $insight->impact_score,
                'confidence_score' => 86,
                'status' => Recommendation::query()
                    ->where('account_id', $account->id)
                    ->where('signal_id', $signal->id)
                    ->where('title', $this->recommendationTitle($insight->type))
                    ->value('status') ?? 'new',
            ],
        );
    }

    private function sumGa4Sessions(ContentAsset $asset, int $fromDaysAgo, int $toDaysAgo): int
    {
        return $this->sumGa4SessionsForAssets([$asset->id], $fromDaysAgo, $toDaysAgo);
    }

    /**
     * @param  array<int, int>  $assetIds
     */
    private function sumGa4SessionsForAssets(array $assetIds, int $fromDaysAgo, int $toDaysAgo): int
    {
        return (int) Ga4MetricSnapshot::query()
            ->whereIn('content_asset_id', $assetIds)
            ->whereBetween('date', [now()->subDays($fromDaysAgo)->toDateString(), now()->subDays($toDaysAgo)->toDateString()])
            ->sum('sessions');
    }

    /**
     * @return array{clicks: int, impressions: int, ctr: float|null, position: float|null}
     */
    private function searchAggregate(SearchConsoleQuerySnapshot $key, int $fromDaysAgo, int $toDaysAgo): array
    {
        $row = SearchConsoleQuerySnapshot::query()
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(ctr) as ctr, AVG(position) as position')
            ->where('content_asset_id', $key->content_asset_id)
            ->where('query', $key->query)
            ->whereBetween('date', [now()->subDays($fromDaysAgo)->toDateString(), now()->subDays($toDaysAgo)->toDateString()])
            ->first();

        return [
            'clicks' => (int) ($row?->clicks ?? 0),
            'impressions' => (int) ($row?->impressions ?? 0),
            'ctr' => $row?->ctr !== null ? (float) $row->ctr : null,
            'position' => $row?->position !== null ? (float) $row->position : null,
        ];
    }

    private function severityForDrop(int $dropPercent): string
    {
        return match (true) {
            $dropPercent >= 70 => 'critical',
            $dropPercent >= 45 => 'high',
            $dropPercent >= 30 => 'medium',
            default => 'low',
        };
    }

    private function signalType(string $type): string
    {
        return match ($type) {
            'ranking_drop', 'ctr_opportunity', 'visibility_gap' => 'visibility_change',
            'social_gap', 'campaign_underperformance' => 'social_opportunity',
            default => 'content_opportunity',
        };
    }

    private function signalCategory(string $type): string
    {
        return match ($type) {
            'ranking_drop', 'ctr_opportunity', 'visibility_gap' => 'visibility',
            'social_gap', 'campaign_underperformance' => 'social',
            default => 'content',
        };
    }

    private function priority(string $severity): string
    {
        return $severity === 'critical' ? 'critical' : ($severity === 'high' ? 'high' : 'medium');
    }

    private function recommendationTitle(string $type): string
    {
        return match ($type) {
            'traffic_drop' => 'Diagnose traffic decline',
            'ranking_drop' => 'Recover ranking loss',
            'ctr_opportunity' => 'Improve search CTR',
            'social_gap' => 'Create social distribution',
            'translation_gap' => 'Complete translations',
            'visibility_gap' => 'Improve AI visibility',
            'content_decay' => 'Refresh decaying content',
            'campaign_underperformance' => 'Recover campaign performance',
            default => 'Review performance insight',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function actionPayload(PerformanceInsight $insight): ?array
    {
        $payload = array_filter([
            'content_asset_id' => $insight->content_asset_id,
            'campaign_id' => $insight->campaign_id,
            'provider' => $insight->payload['provider'] ?? null,
            'query' => $insight->payload['query'] ?? null,
        ], fn (mixed $value) => $value !== null);

        if (($insight->payload['missing_languages'] ?? []) !== []) {
            $payload['target_languages'] = $insight->payload['missing_languages'];
        }

        return $payload === [] ? null : $payload;
    }

    private function actionType(string $type): ?string
    {
        return match ($type) {
            'traffic_drop', 'ranking_drop', 'content_decay' => 'run_content_audit',
            'ctr_opportunity' => 'refresh_content',
            'social_gap', 'campaign_underperformance' => 'create_social_post',
            'translation_gap' => 'translate_content',
            'visibility_gap' => 'run_visibility_check',
            default => null,
        };
    }

    private function defaultRecommendation(string $type): string
    {
        return match ($type) {
            'traffic_drop' => 'Compare channel, search and publishing changes, then refresh the affected content.',
            'ranking_drop' => 'Review competing pages, update the content and strengthen internal links.',
            'ctr_opportunity' => 'Rewrite the title and meta description around the highest-intent query.',
            'social_gap' => 'Create a social post and schedule follow-up distribution.',
            'translation_gap' => 'Translate the asset into the enabled languages with missing coverage.',
            'visibility_gap' => 'Create answer-led content and improve cited source coverage.',
            'content_decay' => 'Refresh outdated sections and rerun lifecycle scoring.',
            'campaign_underperformance' => 'Review campaign assets and schedule recovery distribution.',
            default => 'Review the underlying performance data and choose the next best action.',
        };
    }
}
