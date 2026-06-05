<?php

namespace App\Services\ContentLifecycle;

use App\Enums\CampaignStatus;
use App\Enums\ContentDecayRiskLevel;
use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentRecommendationStatus;
use App\Enums\ContentRefreshTaskStatus;
use App\Enums\ContentRefreshTaskType;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentAiVisibilitySnapshot;
use App\Models\ContentLifecycleAnalysis;
use App\Models\ContentLifecycleEvent;
use App\Models\ContentPerformanceMetric;
use App\Models\ContentRecommendation;
use App\Models\ContentRefreshTask;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContentLifecycleDecayEngine
{
    public function analyze(Content $content, ?Carbon $analyzedAt = null): ContentLifecycleAnalysis
    {
        $analyzedAt ??= now();

        return DB::transaction(function () use ($content, $analyzedAt): ContentLifecycleAnalysis {
            $content->refresh();

            $signals = $this->signalsFor($content, $analyzedAt);
            $scores = $this->score($content, $signals);
            $riskLevel = $this->riskLevel($scores['decay_score']);
            $recommendations = $this->refreshRecommendations($content, $signals, $scores, $riskLevel);
            $campaignSuggestions = $this->campaignReconnectSuggestions($content, $scores);
            $relatedSuggestions = $this->relatedContentSuggestions($content);
            $linkSuggestions = $this->internalLinkingSuggestions($content, $relatedSuggestions, $signals);

            $analysis = ContentLifecycleAnalysis::query()->create([
                'content_id' => $content->id,
                'workspace_id' => $content->workspace_id,
                'client_site_id' => $content->client_site_id,
                'lifecycle_score' => $scores['lifecycle_score'],
                'decay_score' => $scores['decay_score'],
                'decay_risk_level' => $riskLevel->value,
                'refresh_priority_score' => $scores['refresh_priority_score'],
                'confidence_score' => $scores['confidence_score'],
                'signals' => $signals,
                'score_breakdown' => $scores['breakdown'],
                'refresh_recommendations' => $recommendations,
                'campaign_reconnect_suggestions' => $campaignSuggestions,
                'related_content_suggestions' => $relatedSuggestions,
                'internal_linking_suggestions' => $linkSuggestions,
                'analyzed_at' => $analyzedAt,
            ]);

            $this->updateContentHealth($content, $scores, $riskLevel);
            $this->materializeTasks($content, $analysis, $recommendations, $campaignSuggestions, $relatedSuggestions, $linkSuggestions);
            $this->recordLifecycleEvent($content, $analysis, $riskLevel);

            return $analysis;
        });
    }

    public function runForWorkspace(Workspace $workspace, int $limit = 500): int
    {
        $processed = 0;

        Content::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function (Collection $contents) use (&$processed, $limit): bool {
                $ids = $contents->pluck('id')->all();

                Content::query()
                    ->whereIn('id', $ids)
                    ->with(['campaignContents:id,content_id,campaign_id'])
                    ->get()
                    ->each(function (Content $content) use (&$processed, $limit): void {
                        if ($processed >= $limit) {
                            return;
                        }

                        $this->analyze($content);
                        $processed++;
                    });

                return $processed < $limit;
            });

        return $processed;
    }

    /**
     * @return array<string,mixed>
     */
    private function signalsFor(Content $content, Carbon $analyzedAt): array
    {
        $lastTouchedAt = $content->first_published_at ?: $content->updated_at ?: $content->created_at;
        $ageDays = $lastTouchedAt ? $lastTouchedAt->diffInDays($analyzedAt) : null;
        $freshnessScore = $content->freshness_score ?? $this->freshnessFromAge($ageDays);
        $visibilityTrend = $this->aiVisibilityTrend($content);
        $engagement = $this->engagementSignals($content);

        return [
            'rankings' => [
                'available' => false,
                'reason' => 'No ranking ingestion record is linked to this content yet.',
            ],
            'impressions' => [
                'current' => $engagement['views'],
                'previous' => data_get($engagement, 'previous_views'),
                'decline_percent' => data_get($engagement, 'views_decline_percent'),
            ],
            'ctr' => [
                'available' => false,
                'reason' => 'CTR ingestion is not yet mapped to the content lifecycle store.',
            ],
            'ai_citations' => [
                'latest_count' => $visibilityTrend['latest_citations'],
                'previous_count' => $visibilityTrend['previous_citations'],
                'decline' => $visibilityTrend['citation_decline'],
            ],
            'ai_visibility' => [
                'score' => $content->ai_visibility_score,
                'latest_snapshot_score' => $visibilityTrend['latest_score'],
                'previous_snapshot_score' => $visibilityTrend['previous_score'],
                'decline_points' => $visibilityTrend['decline_points'],
            ],
            'engagement_decline' => $engagement,
            'stale_timestamps' => [
                'last_touched_at' => $lastTouchedAt?->toIso8601String(),
                'age_days' => $ageDays,
                'freshness_score' => $freshnessScore,
                'is_stale' => ($ageDays ?? 0) >= 120 || $freshnessScore < 45,
            ],
            'missing_entity_coverage' => [
                'semantic_coverage_score' => $content->semantic_coverage_score,
                'is_missing' => ($content->semantic_coverage_score ?? 100) < 55,
            ],
            'internal_links' => [
                'score' => $content->internal_link_score,
                'is_weak' => ($content->internal_link_score ?? 100) < 55,
            ],
            'answer_coverage' => [
                'score' => $content->answer_block_score,
                'persisted_blocks' => (int) ($content->answer_block_generation_persisted_count ?? 0),
                'is_missing' => ($content->answer_block_score ?? 100) < 50 || (int) ($content->answer_block_generation_persisted_count ?? 0) < 1,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{lifecycle_score:float,decay_score:float,refresh_priority_score:float,confidence_score:float,breakdown:array<string,float|int|null>}
     */
    private function score(Content $content, array $signals): array
    {
        $freshness = (float) data_get($signals, 'stale_timestamps.freshness_score', 60);
        $aiVisibility = (float) ($content->ai_visibility_score ?? data_get($signals, 'ai_visibility.latest_snapshot_score', 55) ?? 55);
        $engagement = (float) data_get($signals, 'engagement_decline.engagement_score', $content->content_health_score ?? 55);
        $internalLinks = (float) ($content->internal_link_score ?? 55);
        $entityCoverage = (float) ($content->semantic_coverage_score ?? 55);
        $answerCoverage = (float) ($content->answer_block_score ?? 55);
        $visibilityDecline = min(35, max(0, (float) data_get($signals, 'ai_visibility.decline_points', 0)));
        $engagementDecline = min(35, max(0, (float) data_get($signals, 'engagement_decline.read_rate_decline_points', 0)));

        $lifecycleScore = $this->clamp(
            ($freshness * 0.22) + ($aiVisibility * 0.2) + ($engagement * 0.22) + ($internalLinks * 0.14) + ($entityCoverage * 0.14) + ($answerCoverage * 0.08)
        );

        $decayScore = $this->clamp(
            ((100 - $freshness) * 0.24) + ((100 - $aiVisibility) * 0.2) + ((100 - $engagement) * 0.18)
            + ((100 - $internalLinks) * 0.13) + ((100 - $entityCoverage) * 0.13) + ((100 - $answerCoverage) * 0.07)
            + ($visibilityDecline * 0.03) + ($engagementDecline * 0.02)
        );

        $confidenceSignals = collect([
            $content->freshness_score,
            $content->ai_visibility_score,
            $content->content_health_score,
            $content->internal_link_score,
            $content->semantic_coverage_score,
            data_get($signals, 'ai_visibility.latest_snapshot_score'),
            data_get($signals, 'engagement_decline.views'),
        ])->filter(fn ($value): bool => $value !== null)->count();

        return [
            'lifecycle_score' => round($lifecycleScore, 2),
            'decay_score' => round($decayScore, 2),
            'refresh_priority_score' => round($this->clamp(($decayScore * 0.72) + ((100 - $lifecycleScore) * 0.28)), 2),
            'confidence_score' => round(min(95, 35 + ($confidenceSignals * 8)), 2),
            'breakdown' => [
                'freshness_score' => $freshness,
                'ai_visibility_score' => $aiVisibility,
                'engagement_score' => $engagement,
                'internal_link_score' => $internalLinks,
                'entity_coverage_score' => $entityCoverage,
                'answer_coverage_score' => $answerCoverage,
                'ai_visibility_decline_points' => $visibilityDecline,
                'engagement_decline_points' => $engagementDecline,
            ],
        ];
    }

    private function riskLevel(float $decayScore): ContentDecayRiskLevel
    {
        return match (true) {
            $decayScore >= 70 => ContentDecayRiskLevel::CRITICAL,
            $decayScore >= 55 => ContentDecayRiskLevel::HIGH,
            $decayScore >= 35 => ContentDecayRiskLevel::MEDIUM,
            default => ContentDecayRiskLevel::LOW,
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     * @param  array<string,mixed>  $scores
     * @return list<array<string,mixed>>
     */
    private function refreshRecommendations(Content $content, array $signals, array $scores, ContentDecayRiskLevel $riskLevel): array
    {
        $items = [];

        if (in_array($riskLevel, [ContentDecayRiskLevel::HIGH, ContentDecayRiskLevel::CRITICAL], true)) {
            $items[] = $this->recommendation(ContentRefreshTaskType::REFRESH_CONTENT, 'Refresh declining content', 'Update dated sections, strengthen the core answer, and revalidate the target query intent.', $scores['refresh_priority_score'], [
                'decay_score' => $scores['decay_score'],
                'stale_timestamps' => data_get($signals, 'stale_timestamps'),
                'engagement_decline' => data_get($signals, 'engagement_decline'),
            ]);
        }

        if ((bool) data_get($signals, 'internal_links.is_weak')) {
            $items[] = $this->recommendation(ContentRefreshTaskType::IMPROVE_INTERNAL_LINKS, 'Add missing internal links', 'Connect this item to stronger related articles and campaign pages.', 70, [
                'internal_link_score' => data_get($signals, 'internal_links.score'),
            ]);
        }

        if (((float) ($content->ai_visibility_score ?? 100) < 50) || ((float) data_get($signals, 'ai_visibility.decline_points', 0) >= 10)) {
            $items[] = $this->recommendation(ContentRefreshTaskType::RESTORE_AI_VISIBILITY, 'Restore AI visibility', 'Improve answer clarity, citation-worthy structure, and entity coverage before the next AI visibility check.', 75, [
                'ai_visibility' => data_get($signals, 'ai_visibility'),
                'ai_citations' => data_get($signals, 'ai_citations'),
            ]);
        }

        if ((bool) data_get($signals, 'missing_entity_coverage.is_missing')) {
            $items[] = $this->recommendation(ContentRefreshTaskType::UPDATE_ENTITY_COVERAGE, 'Fill entity coverage gaps', 'Add missing entities, definitions, and contextual sections needed to support the article topic.', 62, [
                'semantic_coverage_score' => data_get($signals, 'missing_entity_coverage.semantic_coverage_score'),
            ]);
        }

        if ((bool) data_get($signals, 'answer_coverage.is_missing')) {
            $items[] = $this->recommendation(ContentRefreshTaskType::RELATED_CONTENT_SUPPORT, 'Add supporting answer blocks', 'Create or update answer blocks that cover adjacent buyer questions.', 55, [
                'answer_coverage' => data_get($signals, 'answer_coverage'),
            ]);
        }

        return $items;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function campaignReconnectSuggestions(Content $content, array $scores): array
    {
        if ($scores['refresh_priority_score'] < 45) {
            return [];
        }

        return Campaign::query()
            ->where('workspace_id', $content->workspace_id)
            ->whereIn('status', [CampaignStatus::PLANNING->value, CampaignStatus::SCHEDULED->value, CampaignStatus::ACTIVE->value])
            ->when($content->client_site_id, fn (Builder $query) => $query->where(function (Builder $nested) use ($content): void {
                $nested->whereNull('client_site_id')->orWhere('client_site_id', $content->client_site_id);
            }))
            ->latest('updated_at')
            ->limit(3)
            ->get(['id', 'name', 'status', 'objective'])
            ->map(fn (Campaign $campaign): array => [
                'campaign_id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status?->value,
                'reason' => 'Refresh priority is high enough to consider reconnecting this content to active planning.',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function relatedContentSuggestions(Content $content): array
    {
        $keyword = trim((string) $content->primary_keyword);

        if (! $content->series_id && $keyword === '') {
            return [];
        }

        return Content::query()
            ->where('workspace_id', $content->workspace_id)
            ->whereKeyNot($content->id)
            ->whereNull('deleted_at')
            ->when($content->client_site_id, fn (Builder $query) => $query->where('client_site_id', $content->client_site_id))
            ->where(function (Builder $query) use ($content, $keyword): void {
                if ($content->series_id) {
                    $query->orWhere('series_id', $content->series_id);
                }

                if ($keyword !== '') {
                    $query->orWhere('primary_keyword', 'like', '%'.$keyword.'%')
                        ->orWhere('title', 'like', '%'.$keyword.'%');
                }
            })
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'title', 'primary_keyword', 'content_health_score', 'internal_link_score'])
            ->map(fn (Content $related): array => [
                'content_id' => $related->id,
                'title' => $related->title,
                'primary_keyword' => $related->primary_keyword,
                'reason' => $related->series_id === $content->series_id ? 'Same content series' : 'Keyword/title overlap',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $relatedSuggestions
     * @param  array<string,mixed>  $signals
     * @return list<array<string,mixed>>
     */
    private function internalLinkingSuggestions(Content $content, array $relatedSuggestions, array $signals): array
    {
        if (! (bool) data_get($signals, 'internal_links.is_weak') && $relatedSuggestions === []) {
            return [];
        }

        return collect($relatedSuggestions)
            ->take(5)
            ->map(fn (array $related): array => [
                'source_content_id' => $content->id,
                'target_content_id' => $related['content_id'],
                'target_title' => $related['title'],
                'suggested_anchor' => $related['primary_keyword'] ?: $related['title'],
                'reason' => 'Improve topical connectivity for a weak internal link score.',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $recommendations
     * @param  list<array<string,mixed>>  $campaignSuggestions
     * @param  list<array<string,mixed>>  $relatedSuggestions
     * @param  list<array<string,mixed>>  $linkSuggestions
     */
    private function materializeTasks(Content $content, ContentLifecycleAnalysis $analysis, array $recommendations, array $campaignSuggestions, array $relatedSuggestions, array $linkSuggestions): void
    {
        foreach ($recommendations as $recommendation) {
            $this->upsertTask($content, $analysis, $recommendation['type'], $recommendation['title'], $recommendation['description'], (int) $recommendation['priority'], [$recommendation['action']], $recommendation['evidence']);
            $this->upsertRecommendation($content, $recommendation);
        }

        if ($campaignSuggestions !== []) {
            $this->upsertTask($content, $analysis, ContentRefreshTaskType::RECONNECT_CAMPAIGN, 'Reconnect refreshed content to a campaign', 'Review active campaigns that can reuse or relaunch this asset.', 64, ['Review campaign reconnect suggestions and attach the asset where relevant.'], ['campaign_suggestions' => $campaignSuggestions], $campaignSuggestions[0]['campaign_id'] ?? null);
        }

        if ($linkSuggestions !== []) {
            $this->upsertTask($content, $analysis, ContentRefreshTaskType::IMPROVE_INTERNAL_LINKS, 'Review internal link opportunities', 'Add contextual links from related articles to strengthen topical coverage.', 68, ['Review target articles and add contextual anchors.'], ['internal_linking_suggestions' => $linkSuggestions, 'related_content_suggestions' => $relatedSuggestions]);
        }
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function upsertTask(Content $content, ContentLifecycleAnalysis $analysis, ContentRefreshTaskType $type, string $title, string $description, int $priority, array $actions, array $evidence, ?string $campaignId = null): void
    {
        ContentRefreshTask::query()->updateOrCreate(
            [
                'content_id' => $content->id,
                'type' => $type->value,
                'status' => ContentRefreshTaskStatus::OPEN->value,
            ],
            [
                'workspace_id' => $content->workspace_id,
                'client_site_id' => $content->client_site_id,
                'content_lifecycle_analysis_id' => $analysis->id,
                'campaign_id' => $campaignId,
                'priority' => max(1, min(100, $priority)),
                'title' => $title,
                'description' => $description,
                'recommended_actions' => $actions,
                'evidence' => $evidence,
                'due_at' => now()->addDays($priority >= 75 ? 7 : 21),
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $recommendation
     */
    private function upsertRecommendation(Content $content, array $recommendation): void
    {
        ContentRecommendation::query()->updateOrCreate(
            [
                'content_id' => $content->id,
                'type' => $recommendation['type']->value,
                'status' => ContentRecommendationStatus::PENDING->value,
            ],
            [
                'priority' => $recommendation['priority'] >= 75 ? 'high' : ($recommendation['priority'] >= 55 ? 'medium' : 'low'),
                'payload' => [
                    'title' => $recommendation['title'],
                    'description' => $recommendation['description'],
                    'action' => $recommendation['action'],
                    'evidence' => $recommendation['evidence'],
                ],
                'generated_by' => 'content_lifecycle_decay_engine',
            ]
        );
    }

    private function updateContentHealth(Content $content, array $scores, ContentDecayRiskLevel $riskLevel): void
    {
        $updates = [
            'content_health_score' => (int) round($scores['lifecycle_score']),
            'decay_risk_level' => $riskLevel->value,
            'optimization_opportunity_score' => (int) round($scores['refresh_priority_score']),
            'content_intelligence_computed_at' => now(),
        ];

        if (in_array($riskLevel, [ContentDecayRiskLevel::HIGH, ContentDecayRiskLevel::CRITICAL], true)) {
            $updates['lifecycle_stage'] = ContentLifecycleStatus::REFRESH_NEEDED->value;
        }

        $content->forceFill($updates)->save();
    }

    private function recordLifecycleEvent(Content $content, ContentLifecycleAnalysis $analysis, ContentDecayRiskLevel $riskLevel): void
    {
        if (! in_array($riskLevel, [ContentDecayRiskLevel::HIGH, ContentDecayRiskLevel::CRITICAL], true)) {
            return;
        }

        ContentLifecycleEvent::query()->create([
            'content_id' => $content->id,
            'from_stage' => $content->getOriginal('lifecycle_stage'),
            'to_stage' => $content->lifecycle_stage,
            'event_type' => ContentLifecycleEvent::TYPE_DECAY_DETECTED,
            'actor_type' => ContentLifecycleEvent::ACTOR_SYSTEM,
            'notes' => 'Lifecycle decay analysis detected content that needs refresh review.',
            'metadata' => [
                'analysis_id' => $analysis->id,
                'decay_score' => $analysis->decay_score,
                'decay_risk_level' => $riskLevel->value,
                'refresh_priority_score' => $analysis->refresh_priority_score,
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function aiVisibilityTrend(Content $content): array
    {
        $snapshots = ContentAiVisibilitySnapshot::query()
            ->where('content_id', $content->id)
            ->latest('captured_at')
            ->limit(2)
            ->get(['visibility_score', 'citation_count', 'captured_at']);

        $latest = $snapshots->first();
        $previous = $snapshots->get(1);
        $declinePoints = $latest && $previous ? max(0, (int) $previous->visibility_score - (int) $latest->visibility_score) : 0;

        return [
            'latest_score' => $latest?->visibility_score,
            'previous_score' => $previous?->visibility_score,
            'decline_points' => $declinePoints,
            'latest_citations' => $latest?->citation_count,
            'previous_citations' => $previous?->citation_count,
            'citation_decline' => $latest && $previous ? max(0, (int) $previous->citation_count - (int) $latest->citation_count) : 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function engagementSignals(Content $content): array
    {
        $metric = ContentPerformanceMetric::query()
            ->where('content_id', $content->id)
            ->latest('last_seen_at')
            ->first(['views', 'reads', 'read_rate', 'meta']);

        $readRate = $metric?->read_rate;
        $previousReadRate = data_get($metric?->meta, 'previous_read_rate');
        $readRateDecline = $readRate !== null && $previousReadRate !== null ? max(0, ((float) $previousReadRate - (float) $readRate) * 100) : 0;
        $score = $content->content_health_score ?? ($readRate !== null ? (int) round($readRate * 100) : 55);

        return [
            'views' => $metric?->views,
            'reads' => $metric?->reads,
            'read_rate' => $readRate,
            'previous_read_rate' => $previousReadRate,
            'read_rate_decline_points' => round($readRateDecline, 2),
            'views_decline_percent' => data_get($metric?->meta, 'views_decline_percent'),
            'engagement_score' => $score,
        ];
    }

    private function freshnessFromAge(?int $ageDays): int
    {
        if ($ageDays === null) {
            return 55;
        }

        return (int) $this->clamp(100 - min(80, ($ageDays / 365) * 80));
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function recommendation(ContentRefreshTaskType $type, string $title, string $description, float $priority, array $evidence): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'priority' => (int) round(max(1, min(100, $priority))),
            'action' => $description,
            'evidence' => $evidence,
        ];
    }

    private function clamp(float $value): float
    {
        return max(0, min(100, $value));
    }
}
