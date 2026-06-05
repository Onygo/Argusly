<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\ContentAsset;
use App\Models\IntelligenceSignal;
use App\Models\Mention;
use App\Models\Recommendation;
use App\Models\Topic;
use App\Models\VisibilityProviderRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OpportunityDiscoveryService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(Account $account, Brand $brand): array
    {
        $opportunities = $this->opportunities($account, $brand);

        return [
            'stats' => [
                'total' => $opportunities->count(),
                'critical' => $opportunities->where('priority', 'critical')->count(),
                'high' => $opportunities->where('priority', 'high')->count(),
                'projected' => IntelligenceSignal::query()
                    ->where('account_id', $account->id)
                    ->where('brand_id', $brand->id)
                    ->where('source', 'opportunity_discovery')
                    ->open()
                    ->count(),
            ],
            'opportunities' => $opportunities,
            'emergingTopics' => $opportunities->where('type', 'emerging_topic')->values(),
            'trends' => $opportunities->where('type', 'trend_detection')->values(),
            'contentGaps' => $opportunities->where('type', 'content_gap')->values(),
            'aiGaps' => $opportunities->where('type', 'ai_gap')->values(),
            'competitorGaps' => $opportunities->where('type', 'competitor_gap')->values(),
            'marketOpportunities' => $opportunities->where('type', 'market_opportunity')->values(),
            'recommendations' => Recommendation::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->whereHas('signal', fn (Builder $query) => $query->where('source', 'opportunity_discovery'))
                ->latest('created_at')
                ->limit(8)
                ->get(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function opportunities(Account $account, Brand $brand): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return collect()
            ->merge($this->emergingTopics($account, $brand))
            ->merge($this->trendDetection($account, $brand))
            ->merge($this->contentGaps($account, $brand))
            ->merge($this->aiGaps($account, $brand))
            ->merge($this->competitorGaps($account, $brand))
            ->merge($this->marketOpportunities($account, $brand))
            ->map(fn (array $opportunity): array => [
                ...$opportunity,
                'priority' => $this->priority((int) $opportunity['score']),
                'complexity' => $opportunity['complexity'] ?? $this->complexity($opportunity['type']),
            ])
            ->sortByDesc('score')
            ->values();
    }

    /**
     * @return Collection<int, IntelligenceSignal>
     */
    public function project(Account $account, Brand $brand, int $limit = 8): Collection
    {
        return $this->opportunities($account, $brand)
            ->take($limit)
            ->map(fn (array $opportunity): IntelligenceSignal => $this->storeSignal($account, $brand, $opportunity));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function emergingTopics(Account $account, Brand $brand): Collection
    {
        return Topic::query()
            ->where('account_id', $account->id)
            ->where(fn (Builder $query) => $query->whereNull('brand_id')->orWhere('brand_id', $brand->id))
            ->active()
            ->withCount([
                'mentions as recent_mentions_count' => fn (Builder $query) => $query
                    ->where('mentions.account_id', $account->id)
                    ->where('mentions.brand_id', $brand->id)
                    ->where('mentions.published_at', '>=', now()->subDays(14)),
                'mentions as previous_mentions_count' => fn (Builder $query) => $query
                    ->where('mentions.account_id', $account->id)
                    ->where('mentions.brand_id', $brand->id)
                    ->whereBetween('mentions.published_at', [now()->subDays(45), now()->subDays(15)]),
            ])
            ->get()
            ->filter(fn (Topic $topic): bool => $topic->recent_mentions_count >= 2 && $topic->recent_mentions_count > $topic->previous_mentions_count)
            ->map(fn (Topic $topic): array => [
                'id' => "topic:{$topic->id}:emerging",
                'type' => 'emerging_topic',
                'title' => 'Emerging topic: '.$topic->name,
                'summary' => "{$topic->recent_mentions_count} recent mentions, up from {$topic->previous_mentions_count} in the prior window.",
                'score' => min(100, 45 + ($topic->recent_mentions_count * 10) + max(0, ($topic->recent_mentions_count - $topic->previous_mentions_count) * 8)),
                'recommended_action' => 'Create or refresh content around this topic before it becomes crowded.',
                'topic_id' => $topic->id,
                'topic_name' => $topic->name,
                'evidence' => [
                    'recent_mentions' => $topic->recent_mentions_count,
                    'previous_mentions' => $topic->previous_mentions_count,
                ],
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function trendDetection(Account $account, Brand $brand): Collection
    {
        return Mention::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('published_at', '>=', now()->subDays(30))
            ->with('source')
            ->get()
            ->groupBy(fn (Mention $mention): string => $mention->source?->type ?: 'uncategorized')
            ->filter(fn (Collection $mentions): bool => $mentions->count() >= 3)
            ->map(fn (Collection $mentions, string $sourceType): array => [
                'id' => "trend:source:{$sourceType}",
                'type' => 'trend_detection',
                'title' => 'Trend detected in '.Str::headline($sourceType),
                'summary' => "{$mentions->count()} recent mentions clustered in this source category.",
                'score' => min(100, 40 + ($mentions->count() * 8) + (int) round($mentions->avg('impact_score') / 4)),
                'recommended_action' => 'Review the clustered mentions and decide whether this should become a campaign, content or monitoring priority.',
                'source_type' => $sourceType,
                'evidence' => [
                    'mention_count' => $mentions->count(),
                    'avg_impact' => (int) round($mentions->avg('impact_score')),
                ],
            ])
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function contentGaps(Account $account, Brand $brand): Collection
    {
        $topics = Topic::query()
            ->where('account_id', $account->id)
            ->where(fn (Builder $query) => $query->whereNull('brand_id')->orWhere('brand_id', $brand->id))
            ->active()
            ->withCount(['mentions' => fn (Builder $query) => $query->where('mentions.account_id', $account->id)->where('mentions.brand_id', $brand->id)])
            ->get();

        return $topics
            ->map(function (Topic $topic) use ($account, $brand): ?array {
                $assetCount = ContentAsset::query()
                    ->where('account_id', $account->id)
                    ->where('brand_id', $brand->id)
                    ->whereIn('status', ['approved', 'scheduled', 'published'])
                    ->where(function (Builder $query) use ($topic): void {
                        $query->whereHas('topics', fn (Builder $topics) => $topics->whereKey($topic->id))
                            ->orWhere('title', 'like', "%{$topic->name}%")
                            ->orWhere('body', 'like', "%{$topic->name}%");
                    })
                    ->count();

                if ($topic->mentions_count < 2 || $assetCount > 0) {
                    return null;
                }

                return [
                    'id' => "topic:{$topic->id}:content-gap",
                    'type' => 'content_gap',
                    'title' => 'Content gap: '.$topic->name,
                    'summary' => "{$topic->mentions_count} mentions exist for this topic, but no approved or published content covers it.",
                    'score' => min(100, 55 + ($topic->mentions_count * 10)),
                    'recommended_action' => 'Create answer-led content that owns this topic and supports future distribution.',
                    'topic_id' => $topic->id,
                    'topic_name' => $topic->name,
                    'evidence' => [
                        'mention_count' => $topic->mentions_count,
                        'content_assets' => $assetCount,
                    ],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function aiGaps(Account $account, Brand $brand): Collection
    {
        return VisibilityProviderRun::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('status', 'completed')
            ->where('captured_at', '>=', now()->subDays(30))
            ->with(['citations', 'answerEntities'])
            ->get()
            ->filter(fn (VisibilityProviderRun $run): bool => (int) ($run->metadata['visibility_score'] ?? 100) < 65 || $run->citations->isEmpty())
            ->map(fn (VisibilityProviderRun $run): array => [
                'id' => "visibility-run:{$run->id}:ai-gap",
                'type' => 'ai_gap',
                'title' => 'AI gap: '.$run->provider,
                'summary' => 'Prompt scored '.($run->metadata['visibility_score'] ?? '-').' with '.$run->citations->count().' citations.',
                'score' => min(100, 100 - (int) ($run->metadata['visibility_score'] ?? 0) + ($run->citations->isEmpty() ? 25 : 0)),
                'recommended_action' => 'Improve answer-led content and citations, then rerun the visibility prompt.',
                'provider' => $run->provider,
                'query' => $run->query,
                'evidence' => [
                    'visibility_provider_run_id' => $run->id,
                    'visibility_score' => $run->metadata['visibility_score'] ?? null,
                    'citations' => $run->citations->count(),
                    'competitor_entities' => $run->answerEntities->where('entity_type', 'competitor')->pluck('entity_name')->values()->all(),
                ],
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function competitorGaps(Account $account, Brand $brand): Collection
    {
        return Competitor::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->active()
            ->with(['latestSnapshot' => fn ($query) => $query->limit(1)])
            ->get()
            ->map(function (Competitor $competitor): ?array {
                $snapshot = $competitor->latestSnapshot->first();

                if (! $snapshot || (($snapshot->share_of_voice ?? 0) < 50 && ($snapshot->visibility_score ?? 0) < 70)) {
                    return null;
                }

                return [
                    'id' => "competitor:{$competitor->id}:gap",
                    'type' => 'competitor_gap',
                    'title' => 'Competitor gap: '.$competitor->name,
                    'summary' => "{$competitor->name} has {$snapshot->share_of_voice}% share of voice and {$snapshot->visibility_score} visibility score.",
                    'score' => min(100, max((int) $snapshot->share_of_voice, (int) $snapshot->visibility_score) + 10),
                    'recommended_action' => 'Create comparative content and improve narrative/citation coverage against this competitor.',
                    'competitor_id' => $competitor->id,
                    'competitor_name' => $competitor->name,
                    'evidence' => [
                        'competitor_snapshot_id' => $snapshot->id,
                        'share_of_voice' => $snapshot->share_of_voice,
                        'visibility_score' => $snapshot->visibility_score,
                    ],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function marketOpportunities(Account $account, Brand $brand): Collection
    {
        $topics = $this->emergingTopics($account, $brand)->keyBy('topic_id');
        $contentGaps = $this->contentGaps($account, $brand)->keyBy('topic_id');

        return $topics
            ->filter(fn (array $topicOpportunity, int $topicId): bool => $contentGaps->has($topicId))
            ->map(fn (array $topicOpportunity, int $topicId): array => [
                'id' => "topic:{$topicId}:market-opportunity",
                'type' => 'market_opportunity',
                'title' => 'Market opportunity: '.$topicOpportunity['topic_name'],
                'summary' => 'This topic is accelerating and the brand has no strong content coverage yet.',
                'score' => min(100, (int) $topicOpportunity['score'] + 12),
                'recommended_action' => 'Prioritize a campaign brief, content asset and visibility check for this market opportunity.',
                'topic_id' => $topicId,
                'topic_name' => $topicOpportunity['topic_name'],
                'complexity' => 'medium',
                'evidence' => [
                    'emerging_topic' => $topicOpportunity['evidence'],
                    'content_gap' => $contentGaps[$topicId]['evidence'],
                ],
            ])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $opportunity
     */
    private function storeSignal(Account $account, Brand $brand, array $opportunity): IntelligenceSignal
    {
        return app(SignalManager::class)->record($account, [
            'source' => 'opportunity_discovery',
            'type' => $opportunity['type'] === 'ai_gap' ? 'visibility_change' : 'content_opportunity',
            'category' => match ($opportunity['type']) {
                'ai_gap' => 'visibility',
                'competitor_gap' => 'competitor',
                default => 'content',
            },
            'priority' => $opportunity['priority'],
            'severity' => $opportunity['priority'],
            'dedupe_key' => 'opportunity:'.$opportunity['id'],
            'title' => $opportunity['title'],
            'summary' => $opportunity['summary'],
            'impact_score' => $opportunity['score'],
            'confidence_score' => 82,
            'recommended_action' => $opportunity['recommended_action'],
            'payload' => [
                'opportunity_id' => $opportunity['id'],
                'opportunity_type' => $opportunity['type'],
                'score' => $opportunity['score'],
                'complexity' => $opportunity['complexity'],
                'topic_id' => $opportunity['topic_id'] ?? null,
                'competitor_id' => $opportunity['competitor_id'] ?? null,
                'provider' => $opportunity['provider'] ?? null,
                'query' => $opportunity['query'] ?? null,
                'evidence' => $opportunity['evidence'] ?? [],
            ],
        ], $brand);
    }

    private function priority(int $score): string
    {
        return match (true) {
            $score >= 90 => 'critical',
            $score >= 75 => 'high',
            $score >= 55 => 'medium',
            default => 'low',
        };
    }

    private function complexity(string $type): string
    {
        return match ($type) {
            'ai_gap', 'competitor_gap', 'market_opportunity' => 'medium',
            'content_gap' => 'low',
            default => 'low',
        };
    }

    private function ensureBrandBelongsToAccount(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Opportunity discovery brand must belong to the account.');
        }
    }
}
