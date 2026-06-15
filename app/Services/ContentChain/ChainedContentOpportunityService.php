<?php

namespace App\Services\ContentChain;

use App\Models\AnalyticsRollupDaily;
use App\Models\AnalyticsSite;
use App\Models\Content;
use App\Models\ContentChainGuidance;
use App\Models\ContentChainSuggestion;
use App\Models\ContentCluster;
use App\Models\Workspace;
use App\Services\Analytics\ContentPerformanceInsightService;
use App\Support\Analytics\AnalyticsUrlKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ChainedContentOpportunityService
{
    public function __construct(
        private readonly ContentPerformanceInsightService $contentPerformanceInsightService,
        private readonly ChainedContentScoringService $scoringService,
        private readonly ChainedSuggestionGenerator $suggestionGenerator,
        private readonly InlineLinkCandidateMatcher $inlineLinkCandidateMatcher,
        private readonly ContextualLinkInsertionService $contextualLinkInsertionService,
    ) {}

    /**
     * @return array{growth:int,inline:int,footer:int,score:float}
     */
    public function refreshForContent(Content $content): array
    {
        $content->loadMissing(['clientSite.analyticsSite', 'chainGuidance', 'series', 'currentRevision', 'currentVersion']);

        $guidance = $content->chainGuidance;
        $signals = $this->signalsForContent($content, $guidance);
        $targets = $this->candidateTargets($content, $signals['cluster'] ?? null);
        $inlineMode = (string) ($guidance?->inline_link_mode ?: config('content_chain.inline_links.default_mode', 'review'));

        $growthSuggestions = $this->suggestionGenerator->generate($content, $signals, $guidance);
        $linkSuggestions = $inlineMode === 'off'
            ? ['inline' => collect(), 'footer' => collect()]
            : $this->inlineLinkCandidateMatcher->match($content, $targets, $signals, $guidance);

        $this->persistSuggestions($content, $signals, $growthSuggestions, $linkSuggestions, $guidance);

        if ($inlineMode === 'automatic') {
            ContentChainSuggestion::query()
                ->where('source_content_id', $content->id)
                ->whereIn('suggestion_kind', [ContentChainSuggestion::KIND_INLINE_LINK, ContentChainSuggestion::KIND_FOOTER_LINK])
                ->where('status', ContentChainSuggestion::STATUS_SUGGESTED)
                ->update([
                    'status' => ContentChainSuggestion::STATUS_AUTO_APPLIED,
                    'reviewed_at' => now(),
                ]);

            $this->contextualLinkInsertionService->applyApprovedSuggestions($content->fresh(), true);
        }

        return [
            'growth' => $growthSuggestions->count(),
            'inline' => $linkSuggestions['inline']->count(),
            'footer' => $linkSuggestions['footer']->count(),
            'score' => (float) ($signals['source_score'] ?? 0.0),
        ];
    }

    public function refreshForWorkspace(Workspace $workspace): int
    {
        $contents = Content::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', 'archived')
            ->where(function ($query): void {
                $query->where('publish_status', 'published')
                    ->orWhere('status', 'published')
                    ->orWhereNotNull('published_url');
            })
            ->orderBy('updated_at')
            ->get();

        $count = 0;
        foreach ($contents as $content) {
            $this->refreshForContent($content);
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string,mixed>
     */
    private function signalsForContent(Content $content, ?ContentChainGuidance $guidance): array
    {
        $insight = $this->contentPerformanceInsightService->forContent($content);
        $analyticsSite = $content->clientSite?->analyticsSite;
        [$pageViews, $engagedViews] = $this->rollupSignals($content, $analyticsSite);

        $cluster = $this->clusterForContent($content);
        $clusterMembers = collect((array) data_get($cluster?->meta, 'content_ids', []))
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->values();

        $supportingCount = max(0, $clusterMembers->count() - 1);
        $desiredSupport = 4;
        $chainGapScore = min(100.0, (max(0, $desiredSupport - $supportingCount) / $desiredSupport) * 100);
        $topicalGapScore = filled($guidance?->explicit_topic) ? 85.0 : ($supportingCount <= 1 ? 70.0 : 35.0);

        $engagementRate = (float) data_get($insight, 'engagement_rate', 0.0);
        if ($engagementRate <= 0 && $pageViews > 0) {
            $engagementRate = round(($engagedViews / max(1, $pageViews)) * 100, 2);
        }

        $qualityScore = (float) (data_get($insight, 'ai_seo_score') ?? data_get($insight, 'roi_score') ?? 0.0);

        $scored = $this->scoringService->scoreSource([
            'quality_score' => $qualityScore,
            'page_views' => $pageViews,
            'engagement_rate' => $engagementRate,
            'recency_days' => now()->diffInDays($content->updated_at ?? now()),
            'chain_gap_score' => $chainGapScore,
            'manual_priority' => (string) ($guidance?->priority ?? 'medium'),
            'topical_gap_score' => $topicalGapScore,
        ]);

        return [
            'source_score' => $scored['score'],
            'score_breakdown' => $scored['breakdown'],
            'quality_score' => $qualityScore,
            'page_views' => $pageViews,
            'engagement_rate' => $engagementRate,
            'engaged_views' => $engagedViews,
            'topic_keyword' => (string) ($cluster?->topic_keyword ?: ($content->primary_keyword ?: $content->title)),
            'primary_keyword' => (string) ($content->primary_keyword ?: ''),
            'target_audience' => (string) ($guidance?->target_audience ?: ''),
            'target_intent' => (string) ($guidance?->target_intent ?: ''),
            'cluster' => $cluster,
            'cluster_member_ids' => $clusterMembers->all(),
            'chain_gap_score' => $chainGapScore,
            'topical_gap_score' => $topicalGapScore,
            'manual_priority' => (string) ($guidance?->priority ?? 'medium'),
        ];
    }

    private function clusterForContent(Content $content): ?ContentCluster
    {
        return ContentCluster::query()
            ->where('workspace_id', $content->workspace_id)
            ->get()
            ->first(function (ContentCluster $cluster) use ($content): bool {
                if ((string) $cluster->pillar_content_id === (string) $content->id) {
                    return true;
                }

                return collect((array) ($cluster->supporting_content_ids ?? []))
                    ->contains(fn (string $id): bool => (string) $id === (string) $content->id);
            });
    }

    /**
     * @return array{0:int,1:int}
     */
    private function rollupSignals(Content $content, ?AnalyticsSite $analyticsSite): array
    {
        if (! $analyticsSite || ! Schema::hasTable('analytics_rollups_daily')) {
            return [0, 0];
        }

        if (Schema::hasColumn('analytics_rollups_daily', 'article_id')) {
            $row = $this->fetchRollupAggregate(
                AnalyticsRollupDaily::query()
                    ->where('analytics_site_id', $analyticsSite->id)
                    ->where('article_id', $content->id)
            );

            if ($row !== null) {
                return [
                    (int) ($row->page_views ?? 0),
                    (int) ($row->engaged_views ?? 0),
                ];
            }
        }

        $path = AnalyticsUrlKey::normalizePathValue((string) ($content->published_url ?: $content->canonical_url_key ?: $content->publish_url_key));
        $pathHash = $path !== '' ? AnalyticsRollupDaily::computePathHash($path) : '';

        if (Schema::hasColumn('analytics_rollups_daily', 'url_key')) {
            $urlKey = trim((string) ($content->canonical_url_key ?: $content->publish_url_key));
            if ($urlKey !== '') {
                $row = $this->fetchRollupAggregate(
                    AnalyticsRollupDaily::query()
                        ->where('analytics_site_id', $analyticsSite->id)
                        ->where('url_key', $urlKey)
                );

                if ($row !== null) {
                    return [
                        (int) ($row->page_views ?? 0),
                        (int) ($row->engaged_views ?? 0),
                    ];
                }
            }
        }

        if ($pathHash !== '' && Schema::hasColumn('analytics_rollups_daily', 'path_hash')) {
            $row = $this->fetchRollupAggregate(
                AnalyticsRollupDaily::query()
                    ->where('analytics_site_id', $analyticsSite->id)
                    ->where('path_hash', $pathHash)
            );

            if ($row !== null) {
                return [
                    (int) ($row->page_views ?? 0),
                    (int) ($row->engaged_views ?? 0),
                ];
            }
        }

        return [0, 0];
    }

    private function fetchRollupAggregate(mixed $query): mixed
    {
        $row = $query
            ->selectRaw('COALESCE(SUM(page_views), 0) as page_views')
            ->selectRaw('COALESCE(SUM(engaged_views), 0) as engaged_views')
            ->first();

        if (! $row) {
            return null;
        }

        if ((int) ($row->page_views ?? 0) === 0 && (int) ($row->engaged_views ?? 0) === 0) {
            return null;
        }

        return $row;
    }

    /**
     * @return Collection<int,Content>
     */
    private function candidateTargets(Content $source, ?ContentCluster $cluster): Collection
    {
        $query = Content::query()
            ->with('seriesArticle:id,series_id,content_id,article_number,is_pillar')
            ->where('workspace_id', $source->workspace_id)
            ->where('id', '!=', $source->id)
            ->where('language', $source->localeCode())
            ->where('status', '!=', 'archived')
            ->where(function ($builder): void {
                $builder->where('publish_status', 'published')
                    ->orWhere('status', 'published')
                    ->orWhereNotNull('published_url');
            })
            ->whereNotNull('published_url')
            ->orderByDesc('updated_at');

        $sourceRootId = $source->localizationRootId();
        $targets = $query->get()
            ->reject(fn (Content $target): bool => $target->localizationRootId() === $sourceRootId)
            ->values();

        if (! $cluster) {
            return $targets->take(12)->values();
        }

        $clusterIds = collect([(string) $cluster->pillar_content_id])
            ->merge((array) ($cluster->supporting_content_ids ?? []))
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->reject(fn (string $id): bool => $id === (string) $source->id)
            ->values()
            ->all();

        $source->loadMissing('seriesArticle');
        $sourceIsPillar = (bool) ($source->seriesArticle?->is_pillar ?? false);

        return $targets
            ->sortByDesc(function (Content $target) use ($source, $clusterIds, $sourceIsPillar): int {
                $targetIsPillar = (bool) ($target->seriesArticle?->is_pillar ?? false);

                return ((string) $target->series_id === (string) $source->series_id ? 20 : 0)
                    + (! $sourceIsPillar && $targetIsPillar ? 40 : 0)
                    + ($sourceIsPillar && ! $targetIsPillar ? 25 : 0)
                    + (in_array((string) $target->id, $clusterIds, true) ? 30 : 0)
                    + ((string) $target->client_site_id === (string) $source->client_site_id ? 10 : 0);
            })
            ->take(12)
            ->values();
    }

    /**
     * @param array<string,mixed> $signals
     * @param Collection<int,array<string,mixed>> $growthSuggestions
     * @param array{inline:Collection<int,array<string,mixed>>,footer:Collection<int,array<string,mixed>>} $linkSuggestions
     */
    private function persistSuggestions(
        Content $content,
        array $signals,
        Collection $growthSuggestions,
        array $linkSuggestions,
        ?ContentChainGuidance $guidance
    ): void {
        $fingerprints = [];
        $sourceSnapshot = [
            'source_score' => $signals['source_score'],
            'quality_score' => $signals['quality_score'],
            'page_views' => $signals['page_views'],
            'engagement_rate' => $signals['engagement_rate'],
            'score_breakdown' => $signals['score_breakdown'],
        ];

        $payloads = $growthSuggestions
            ->merge($linkSuggestions['inline'])
            ->merge($linkSuggestions['footer'])
            ->map(function (array $row) use ($content, $signals, $sourceSnapshot): array {
                /** @var Content|null $target */
                $target = $row['target'] ?? null;
                $kind = (string) $row['suggestion_kind'];
                $type = (string) $row['suggestion_type'];
                $fingerprint = sha1(implode('|', [
                    (string) $content->id,
                    $kind,
                    $type,
                    (string) ($target?->id ?? ''),
                    Str::lower(trim((string) ($row['title'] ?? $row['anchor_text'] ?? ''))),
                ]));

                return [
                    'fingerprint' => $fingerprint,
                    'workspace_id' => (string) $content->workspace_id,
                    'source_content_id' => (string) $content->id,
                    'target_content_id' => $target?->id,
                    'content_cluster_id' => data_get($signals, 'cluster.id'),
                    'suggestion_kind' => $kind,
                    'suggestion_type' => $type,
                    'title' => $row['title'] ?? ($target?->title ?? null),
                    'goal_type' => $row['goal_type'] ?? null,
                    'anchor_text' => $row['anchor_text'] ?? null,
                    'placement_type' => $row['placement_type'] ?? null,
                    'placement_label' => $row['placement_label'] ?? null,
                    'rationale' => $row['rationale'] ?? null,
                    'score' => $kind === ContentChainSuggestion::KIND_GROWTH
                        ? (float) ($signals['source_score'] ?? 0.0)
                        : round(((float) ($row['confidence_score'] ?? 0.0)) * 100, 2),
                    'confidence_score' => (float) ($row['confidence_score'] ?? max(0.45, ((float) ($signals['source_score'] ?? 0.0)) / 100)),
                    'score_breakdown' => $kind === ContentChainSuggestion::KIND_GROWTH ? $signals['score_breakdown'] : [
                        'source_score' => (float) ($signals['source_score'] ?? 0.0),
                        'confidence_score' => round(((float) ($row['confidence_score'] ?? 0.0)) * 100, 2),
                    ],
                    'source_snapshot' => $sourceSnapshot,
                    'placement_meta' => $row['placement_meta'] ?? null,
                    'meta' => $row['meta'] ?? [],
                ];
            })
            ->values();

        foreach ($payloads as $payload) {
            $fingerprints[] = (string) $payload['fingerprint'];

            $existing = ContentChainSuggestion::query()
                ->where('workspace_id', $content->workspace_id)
                ->where('fingerprint', $payload['fingerprint'])
                ->first();

            if ($existing) {
                $status = $existing->status;

                $existing->fill($payload);
                if ($status !== ContentChainSuggestion::STATUS_SUGGESTED) {
                    $existing->status = $status;
                }
                $existing->save();

                continue;
            }

            ContentChainSuggestion::query()->create(array_merge($payload, [
                'status' => ContentChainSuggestion::STATUS_SUGGESTED,
            ]));
        }

        ContentChainSuggestion::query()
            ->where('source_content_id', $content->id)
            ->where('status', ContentChainSuggestion::STATUS_SUGGESTED)
            ->whereNotIn('fingerprint', $fingerprints !== [] ? $fingerprints : ['__none__'])
            ->delete();
    }
}
