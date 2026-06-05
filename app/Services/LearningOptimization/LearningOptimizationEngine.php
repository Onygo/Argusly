<?php

namespace App\Services\LearningOptimization;

use App\Enums\LearningRecommendationStatus;
use App\Enums\LearningRecommendationType;
use App\Enums\SocialRepostSuggestionStatus;
use App\Models\Campaign;
use App\Models\CampaignLearningProfile;
use App\Models\Content;
use App\Models\ContentLearningProfile;
use App\Models\LearningRecommendation;
use App\Models\SocialEngagementMetric;
use App\Models\SocialPublication;
use App\Models\SocialRepostSuggestion;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LearningOptimizationEngine
{
    /**
     * @return array{content_profiles:int,campaign_profiles:int,recommendations:int}
     */
    public function run(Workspace $workspace): array
    {
        return DB::transaction(function () use ($workspace): array {
            $contentProfiles = $this->buildContentProfiles($workspace);
            $campaignProfiles = $this->buildCampaignProfiles($workspace);
            $recommendations = 0;

            foreach ($contentProfiles as $profile) {
                $recommendations += $this->recommendForContent($profile);
            }

            foreach ($campaignProfiles as $profile) {
                $recommendations += $this->recommendForCampaign($profile);
            }

            return [
                'content_profiles' => $contentProfiles->count(),
                'campaign_profiles' => $campaignProfiles->count(),
                'recommendations' => $recommendations,
            ];
        });
    }

    /**
     * @return Collection<int,ContentLearningProfile>
     */
    private function buildContentProfiles(Workspace $workspace): Collection
    {
        return Content::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->with(['performanceMetrics', 'aiVisibilitySnapshots', 'socialPostVariants.publications.metrics', 'campaignContents.ctaPreset'])
            ->limit(500)
            ->get()
            ->map(fn (Content $content): ContentLearningProfile => $this->profileContent($content));
    }

    private function profileContent(Content $content): ContentLearningProfile
    {
        $performance = $this->articlePerformance($content);
        $social = $this->socialPerformanceForContent($content);
        $ai = $this->aiVisibilityTrend($content);
        $cta = $this->ctaAnalysis($content);
        $hook = $this->hookAnalysis($content);
        $tone = $this->toneAnalysis($content);
        $topic = $this->topicAnalysis($content);
        $scores = [
            'article_score' => $performance['score'],
            'linkedin_score' => $social['score'],
            'ai_visibility_score' => $ai['score'],
            'conversion_score' => $performance['conversion_score'],
            'cta_score' => $cta['score'],
            'hook_score' => $hook['score'],
            'tone_score' => $tone['score'],
            'topic_score' => $topic['score'],
        ];
        $performanceScore = $this->weightedScore($scores, [
            'article_score' => 0.22,
            'linkedin_score' => 0.16,
            'ai_visibility_score' => 0.18,
            'conversion_score' => 0.12,
            'cta_score' => 0.09,
            'hook_score' => 0.09,
            'tone_score' => 0.06,
            'topic_score' => 0.08,
        ]);

        return ContentLearningProfile::query()->updateOrCreate(
            ['content_id' => (string) $content->id],
            [
                'workspace_id' => (string) $content->workspace_id,
                'client_site_id' => $content->client_site_id,
                'performance_score' => $performanceScore,
                ...$scores,
                'primary_topic' => $topic['primary_topic'],
                'hook_analysis' => $hook,
                'cta_analysis' => $cta,
                'tone_analysis' => $tone,
                'topic_analysis' => $topic,
                'ai_visibility_trend' => $ai,
                'historical_trends' => [
                    'article' => $performance['trend'],
                    'linkedin' => $social['trend'],
                    'ai_visibility' => $ai['trend'],
                ],
                'score_breakdown' => [
                    'weights' => [
                        'article_score' => 0.22,
                        'linkedin_score' => 0.16,
                        'ai_visibility_score' => 0.18,
                        'conversion_score' => 0.12,
                        'cta_score' => 0.09,
                        'hook_score' => 0.09,
                        'tone_score' => 0.06,
                        'topic_score' => 0.08,
                    ],
                    'scores' => $scores,
                ],
                'evidence' => [
                    'article' => $performance['evidence'],
                    'social' => $social['evidence'],
                    'ai_visibility' => $ai['evidence'],
                ],
                'analyzed_at' => now(),
            ]
        );
    }

    /**
     * @return Collection<int,CampaignLearningProfile>
     */
    private function buildCampaignProfiles(Workspace $workspace): Collection
    {
        return Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->with(['contents.content.learningProfile', 'socialPublications.metrics', 'distributionPlans'])
            ->limit(250)
            ->get()
            ->map(fn (Campaign $campaign): CampaignLearningProfile => $this->profileCampaign($campaign));
    }

    private function profileCampaign(Campaign $campaign): CampaignLearningProfile
    {
        $contentProfiles = $campaign->contents
            ->map(fn ($campaignContent) => $campaignContent->content?->learningProfile)
            ->filter();
        $contentScore = (float) ($contentProfiles->avg('performance_score') ?: 0);
        $aiScore = (float) ($contentProfiles->avg('ai_visibility_score') ?: 0);
        $conversionScore = (float) ($contentProfiles->avg('conversion_score') ?: 0);
        $distribution = $this->campaignDistributionAnalysis($campaign);
        $score = $this->weightedScore([
            'content_score' => $contentScore,
            'distribution_score' => $distribution['score'],
            'ai_visibility_score' => $aiScore,
            'conversion_score' => $conversionScore,
        ], [
            'content_score' => 0.34,
            'distribution_score' => 0.26,
            'ai_visibility_score' => 0.2,
            'conversion_score' => 0.2,
        ]);

        return CampaignLearningProfile::query()->updateOrCreate(
            ['campaign_id' => (string) $campaign->id],
            [
                'workspace_id' => (string) $campaign->workspace_id,
                'performance_score' => $score,
                'content_score' => $contentScore,
                'distribution_score' => $distribution['score'],
                'ai_visibility_score' => $aiScore,
                'conversion_score' => $conversionScore,
                'content_mix_analysis' => [
                    'asset_counts' => $campaign->contents->countBy(fn ($content) => (string) ($content->asset_type?->value ?? $content->asset_type))->all(),
                    'top_content_profile_ids' => $contentProfiles->sortByDesc('performance_score')->take(5)->pluck('id')->values()->all(),
                ],
                'channel_analysis' => $distribution,
                'tone_analysis' => $contentProfiles->pluck('tone_analysis')->filter()->values()->all(),
                'topic_analysis' => $contentProfiles->pluck('topic_analysis')->filter()->values()->all(),
                'historical_trends' => [
                    'content_scores' => $contentProfiles->pluck('performance_score')->values()->all(),
                    'distribution' => $distribution['trend'],
                ],
                'score_breakdown' => [
                    'weights' => ['content_score' => 0.34, 'distribution_score' => 0.26, 'ai_visibility_score' => 0.2, 'conversion_score' => 0.2],
                    'scores' => ['content_score' => $contentScore, 'distribution_score' => $distribution['score'], 'ai_visibility_score' => $aiScore, 'conversion_score' => $conversionScore],
                ],
                'evidence' => [
                    'content_profiles_count' => $contentProfiles->count(),
                    'publications_count' => $campaign->socialPublications->count(),
                    'distribution_plans_count' => $campaign->distributionPlans->count(),
                ],
                'analyzed_at' => now(),
            ]
        );
    }

    private function recommendForContent(ContentLearningProfile $profile): int
    {
        $count = 0;

        if ($profile->linkedin_score >= 70 && data_get($profile->evidence, 'social.top_publication_id')) {
            $this->upsertRecommendation($profile->workspace_id, LearningRecommendationType::REPOST, [
                'content_id' => $profile->content_id,
                'content_learning_profile_id' => $profile->id,
                'priority_score' => min(95, $profile->linkedin_score + 10),
                'confidence_score' => 78,
                'title' => 'Repost high-performing LinkedIn content',
                'summary' => 'LinkedIn engagement is materially above baseline; schedule a repost with a fresh angle.',
                'recommended_actions' => ['Create a new variant from the strongest hook.', 'Schedule after the original post has cooled down.', 'Keep the same topic and adjust the opening frame.'],
                'explanation' => ['reason' => 'LinkedIn score crossed the repost threshold.', 'threshold' => 70, 'observed_score' => $profile->linkedin_score],
                'evidence' => data_get($profile->evidence, 'social', []),
                'expected_impact' => ['channel' => 'linkedin', 'impact' => 'additional reach from proven hook/topic fit'],
            ]);
            $this->upsertSocialRepostSuggestion($profile);
            $count++;
        }

        if ($profile->performance_score < 45 || data_get($profile->historical_trends, 'article.read_rate_delta', 0) < -10) {
            $this->upsertRecommendation($profile->workspace_id, LearningRecommendationType::REFRESH, [
                'content_id' => $profile->content_id,
                'content_learning_profile_id' => $profile->id,
                'priority_score' => max(55, 100 - $profile->performance_score),
                'confidence_score' => 72,
                'title' => 'Refresh underperforming content',
                'summary' => 'Article performance or trend signals indicate the content needs a refresh.',
                'recommended_actions' => ['Review stale sections.', 'Improve internal links and answer coverage.', 'Compare AI citation trend before republishing.'],
                'explanation' => ['reason' => 'Performance score is below target or historical trend is declining.', 'observed_score' => $profile->performance_score],
                'evidence' => $profile->evidence,
                'expected_impact' => ['impact' => 'recover reads, AI visibility, and conversion intent'],
            ]);
            $count++;
        }

        if ($profile->ai_visibility_score < 50) {
            $this->upsertRecommendation($profile->workspace_id, LearningRecommendationType::AI_VISIBILITY, [
                'content_id' => $profile->content_id,
                'content_learning_profile_id' => $profile->id,
                'priority_score' => 68,
                'confidence_score' => 70,
                'title' => 'Improve AI visibility',
                'summary' => 'AI citation and visibility signals are below the learning benchmark.',
                'recommended_actions' => ['Add answer-first sections.', 'Strengthen entity coverage.', 'Link supporting content into the page.'],
                'explanation' => ['reason' => 'AI visibility score below 50.', 'observed_score' => $profile->ai_visibility_score],
                'evidence' => $profile->ai_visibility_trend,
                'expected_impact' => ['impact' => 'higher citation eligibility and answer surface coverage'],
            ]);
            $count++;
        }

        if ($profile->hook_score < 45 && data_get($profile->hook_analysis, 'sample_size', 0) > 0) {
            $this->upsertRecommendation($profile->workspace_id, LearningRecommendationType::HOOK_OPTIMIZATION, [
                'content_id' => $profile->content_id,
                'content_learning_profile_id' => $profile->id,
                'priority_score' => 56,
                'confidence_score' => 64,
                'title' => 'Test stronger hooks',
                'summary' => 'Published hooks are not producing enough engagement relative to impressions.',
                'recommended_actions' => ['Create a contrast hook.', 'Create a practical how-to hook.', 'Compare engagement rate after publishing.'],
                'explanation' => ['reason' => 'Hook score below benchmark.', 'observed_score' => $profile->hook_score],
                'evidence' => $profile->hook_analysis,
                'expected_impact' => ['impact' => 'improve first-line engagement and repost performance'],
            ]);
            $count++;
        }

        return $count;
    }

    private function recommendForCampaign(CampaignLearningProfile $profile): int
    {
        if ($profile->performance_score < 70 || $profile->content_score < 60) {
            return 0;
        }

        $this->upsertRecommendation($profile->workspace_id, LearningRecommendationType::CAMPAIGN_EXPANSION, [
            'campaign_id' => $profile->campaign_id,
            'campaign_learning_profile_id' => $profile->id,
            'priority_score' => min(95, $profile->performance_score + 8),
            'confidence_score' => 76,
            'title' => 'Expand high-performing campaign',
            'summary' => 'Campaign learning signals show strong performance across content and distribution.',
            'recommended_actions' => ['Add a supporting article for the strongest topic.', 'Create a new LinkedIn variant from the best hook.', 'Add a newsletter recap to extend reach.'],
            'explanation' => ['reason' => 'Campaign performance score exceeded expansion threshold.', 'observed_score' => $profile->performance_score],
            'evidence' => $profile->evidence,
            'expected_impact' => ['impact' => 'compound proven topic/channel fit with additional assets'],
        ]);

        return 1;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function upsertRecommendation(string $workspaceId, LearningRecommendationType $type, array $attributes): LearningRecommendation
    {
        return LearningRecommendation::query()->updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'content_id' => $attributes['content_id'] ?? null,
                'campaign_id' => $attributes['campaign_id'] ?? null,
                'type' => $type->value,
                'status' => LearningRecommendationStatus::PROPOSED->value,
            ],
            array_merge($attributes, [
                'workspace_id' => $workspaceId,
                'type' => $type->value,
                'status' => LearningRecommendationStatus::PROPOSED->value,
                'recommended_at' => now(),
            ])
        );
    }

    private function upsertSocialRepostSuggestion(ContentLearningProfile $profile): void
    {
        $publicationId = data_get($profile->evidence, 'social.top_publication_id');
        if (! $publicationId) {
            return;
        }

        $publication = SocialPublication::query()->with('variant')->find($publicationId);
        if (! $publication) {
            return;
        }

        SocialRepostSuggestion::query()->updateOrCreate(
            [
                'workspace_id' => $profile->workspace_id,
                'social_publication_id' => (string) $publication->id,
                'status' => SocialRepostSuggestionStatus::PROPOSED->value,
            ],
            [
                'organization_id' => $publication->organization_id,
                'campaign_id' => $publication->campaign_id,
                'platform' => $publication->platform?->value ?? $publication->platform,
                'suggested_for' => now()->addDays(14),
                'reason_code' => 'high_learning_score',
                'reason' => 'Learning engine detected above-baseline LinkedIn engagement.',
                'suggested_angle' => [
                    'source_hook' => $publication->variant?->hook,
                    'angle' => 'Retell the strongest point with a fresh opening hook.',
                ],
                'performance_snapshot' => data_get($profile->evidence, 'social', []),
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function articlePerformance(Content $content): array
    {
        $metrics = $content->performanceMetrics;
        $latest = $metrics->sortByDesc('last_seen_at')->first();
        $previous = $metrics->sortByDesc('last_seen_at')->skip(1)->first();
        $readRate = (float) ($latest?->read_rate ?? 0);
        $readRateNormalized = $readRate <= 1 ? $readRate * 100 : $readRate;
        $viewsScore = min(100, log(max(1, (int) ($latest?->views ?? 0)) + 1, 1.08));
        $score = $this->clamp(($readRateNormalized * 0.58) + ($viewsScore * 0.25) + ((float) ($content->content_health_score ?? 50) * 0.17));
        $conversionCount = (int) data_get($latest?->meta, 'conversions', data_get($latest?->meta, 'conversion_count', 0));

        return [
            'score' => round($score, 2),
            'conversion_score' => min(100, $conversionCount * 12),
            'trend' => [
                'read_rate' => $readRateNormalized,
                'previous_read_rate' => $previous ? (($previous->read_rate <= 1 ? $previous->read_rate * 100 : $previous->read_rate)) : null,
                'read_rate_delta' => $previous ? round($readRateNormalized - (($previous->read_rate <= 1 ? $previous->read_rate * 100 : $previous->read_rate)), 2) : 0,
                'views' => (int) ($latest?->views ?? 0),
            ],
            'evidence' => [
                'metrics_count' => $metrics->count(),
                'latest_views' => (int) ($latest?->views ?? 0),
                'latest_reads' => (int) ($latest?->reads ?? 0),
                'latest_read_rate' => $readRateNormalized,
                'conversion_count' => $conversionCount,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function socialPerformanceForContent(Content $content): array
    {
        $publications = $content->socialPostVariants->flatMap->publications;
        $metrics = $publications->flatMap->metrics;
        $topMetric = $metrics->sortByDesc(fn (SocialEngagementMetric $metric): float => (float) ($metric->engagement_rate ?? 0))->first();
        $avgRate = (float) ($metrics->avg('engagement_rate') ?: 0);
        $rateScore = $avgRate <= 1 ? $avgRate * 500 : $avgRate;
        $clickScore = min(100, (float) $metrics->sum('clicks') * 4);

        return [
            'score' => round($this->clamp(($rateScore * 0.72) + ($clickScore * 0.28)), 2),
            'trend' => [
                'avg_engagement_rate' => $avgRate,
                'metric_count' => $metrics->count(),
                'impressions' => (int) $metrics->sum('impressions'),
                'clicks' => (int) $metrics->sum('clicks'),
            ],
            'evidence' => [
                'publications_count' => $publications->count(),
                'metrics_count' => $metrics->count(),
                'top_publication_id' => $topMetric?->social_publication_id,
                'top_engagement_rate' => $topMetric?->engagement_rate,
                'total_clicks' => (int) $metrics->sum('clicks'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function aiVisibilityTrend(Content $content): array
    {
        $snapshots = $content->aiVisibilitySnapshots->sortByDesc('captured_at')->values();
        $latest = $snapshots->first();
        $previous = $snapshots->get(1);
        $score = (float) ($content->ai_visibility_score ?? $latest?->visibility_score ?? 0);

        return [
            'score' => $score,
            'trend' => [
                'latest_score' => $latest?->visibility_score,
                'previous_score' => $previous?->visibility_score,
                'score_delta' => $latest && $previous ? (int) $latest->visibility_score - (int) $previous->visibility_score : 0,
                'latest_citations' => $latest?->citation_count,
                'previous_citations' => $previous?->citation_count,
                'citation_delta' => $latest && $previous ? (int) $latest->citation_count - (int) $previous->citation_count : 0,
            ],
            'evidence' => [
                'snapshots_count' => $snapshots->count(),
                'latest_provider' => $latest?->provider,
                'entities_detected' => $latest?->entities_detected,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function hookAnalysis(Content $content): array
    {
        $variants = $content->socialPostVariants;
        $rates = $variants->map(function ($variant): array {
            $metrics = $variant->publications->flatMap->metrics;

            return [
                'hook' => $variant->hook,
                'post_type' => $variant->post_type?->value ?? $variant->post_type,
                'avg_engagement_rate' => (float) ($metrics->avg('engagement_rate') ?: 0),
                'publications' => $variant->publications->count(),
            ];
        })->filter(fn (array $row): bool => trim((string) $row['hook']) !== '');
        $best = $rates->sortByDesc('avg_engagement_rate')->first();
        $score = $best ? min(100, (($best['avg_engagement_rate'] <= 1 ? $best['avg_engagement_rate'] * 500 : $best['avg_engagement_rate']) * 1.2)) : 0;

        return [
            'score' => round($score, 2),
            'sample_size' => $rates->count(),
            'best_hook' => $best,
            'patterns' => $rates->pluck('post_type')->filter()->countBy()->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ctaAnalysis(Content $content): array
    {
        $campaignContents = $content->campaignContents;
        $presets = $campaignContents->pluck('ctaPreset.name')->filter()->values();
        $conversionSignals = $content->performanceMetrics->pluck('meta')->map(fn ($meta) => (array) $meta);
        $conversionCount = (int) $conversionSignals->sum(fn (array $meta): int => (int) (data_get($meta, 'conversions') ?? data_get($meta, 'conversion_count') ?? 0));
        $score = $presets->isNotEmpty() ? min(100, 45 + ($conversionCount * 10)) : min(40, $conversionCount * 10);

        return [
            'score' => $score,
            'cta_presets' => $presets->unique()->values()->all(),
            'conversion_count' => $conversionCount,
            'analysis' => $presets->isNotEmpty() ? 'CTA preset is attached; score depends on observed conversion signals.' : 'No CTA preset attached to campaign content.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function toneAnalysis(Content $content): array
    {
        $tones = $content->socialPostVariants
            ->map(fn ($variant) => data_get($variant->generation_prompt_context, 'tone') ?: data_get($variant->metadata, 'tone') ?: $variant->post_type?->value)
            ->filter()
            ->values();
        $engagement = $content->socialPostVariants->mapWithKeys(function ($variant): array {
            $tone = (string) (data_get($variant->generation_prompt_context, 'tone') ?: data_get($variant->metadata, 'tone') ?: $variant->post_type?->value ?: 'unspecified');

            return [$tone => (float) ($variant->publications->flatMap->metrics->avg('engagement_rate') ?: 0)];
        });
        $bestTone = $engagement->sortDesc()->keys()->first();
        $score = $engagement->isEmpty() ? 0 : min(100, (($engagement->max() <= 1 ? $engagement->max() * 500 : $engagement->max()) * 1.15));

        return [
            'score' => round($score, 2),
            'tones_used' => $tones->unique()->values()->all(),
            'best_tone' => $bestTone,
            'engagement_by_tone' => $engagement->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function topicAnalysis(Content $content): array
    {
        $topic = Str::of((string) ($content->primary_keyword ?: $content->title))->lower()->squish()->limit(120, '')->toString();
        $score = $this->clamp((float) ($content->semantic_coverage_score ?? 50) * 0.45 + (float) ($content->content_health_score ?? 50) * 0.35 + (float) ($content->ai_visibility_score ?? 50) * 0.2);

        return [
            'score' => round($score, 2),
            'primary_topic' => $topic,
            'semantic_coverage_score' => $content->semantic_coverage_score,
            'topic_performance_factors' => ['semantic coverage', 'content health', 'AI visibility'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function campaignDistributionAnalysis(Campaign $campaign): array
    {
        $metrics = $campaign->socialPublications->flatMap->metrics;
        $avgRate = (float) ($metrics->avg('engagement_rate') ?: 0);
        $score = $this->clamp((($avgRate <= 1 ? $avgRate * 500 : $avgRate) * 0.72) + min(100, $metrics->sum('clicks') * 3) * 0.28);

        return [
            'score' => round($score, 2),
            'trend' => [
                'avg_engagement_rate' => $avgRate,
                'impressions' => (int) $metrics->sum('impressions'),
                'clicks' => (int) $metrics->sum('clicks'),
            ],
            'channels' => $campaign->distributionPlans->pluck('distributionChannel.type')->filter()->map(fn ($type) => $type->value ?? (string) $type)->countBy()->all(),
        ];
    }

    /**
     * @param  array<string,float|int>  $scores
     * @param  array<string,float>  $weights
     */
    private function weightedScore(array $scores, array $weights): float
    {
        $weighted = collect($scores)->reduce(
            fn (float $carry, float|int $score, string $key): float => $carry + ((float) $score * (float) ($weights[$key] ?? 0)),
            0.0
        );

        return round($this->clamp($weighted), 2);
    }

    private function clamp(float $value): float
    {
        return max(0, min(100, $value));
    }
}
