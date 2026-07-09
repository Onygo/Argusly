<?php

namespace App\Services\AgenticMarketing\Intelligence;

class OpportunityEngine
{
    public function __construct(private readonly RecommendationPriority $priority)
    {
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, MarketingInsight>  $insights
     * @return array<int, MarketingRecommendation>
     */
    public function recommendations(array $context, array $insights): array
    {
        $recommendations = [];

        foreach ((array) ($context['pages'] ?? []) as $page) {
            $pageInsights = $this->pageInsights($insights, (string) ($page['id'] ?? ''));
            $conflict = $this->firstByKeyPart($pageInsights, 'traffic-growth-engagement-decline');
            $competitor = $this->firstByKeyPart($pageInsights, 'competitor-pressure');
            $aiGap = $this->firstByKeyPart($pageInsights, 'ai-visibility-gap');
            $searchGap = $this->firstByKeyPart($pageInsights, 'search-visibility-gap');
            $prAmplification = $this->firstByKeyPart($pageInsights, 'amplify-earned-media');

            if ($conflict instanceof MarketingInsight && ($competitor instanceof MarketingInsight || $aiGap instanceof MarketingInsight || $searchGap instanceof MarketingInsight)) {
                $support = array_values(array_filter([$conflict, $competitor, $aiGap, $searchGap, $prAmplification]));
                $recommendations[] = $this->recommendation(
                    key: 'opportunity:compound-page-improvement:'.(string) $page['id'],
                    type: 'content_momentum_opportunity',
                    title: 'Improve the article and expand supporting demand channels',
                    summary: 'Traffic momentum exists, but engagement, competitor pressure, and visibility gaps show that the page needs both content improvement and channel support.',
                    insights: $support,
                    actions: [
                        'Improve the affected article with clearer intent matching, stronger answer blocks, and current proof.',
                        'Publish LinkedIn or social content that reframes the strongest topic angle from the article.',
                        'Issue a PR or earned-media campaign if the page has PR value or competitor pressure evidence.',
                        'Expand the topic with a supporting article or section that closes the highest-confidence gap.',
                    ],
                    impact: $this->maxSeverity($support),
                    risk: $competitor instanceof MarketingInsight ? 78 : 55,
                    opportunity: 90,
                    metadata: ['reasoning_pattern' => 'traffic_rising_engagement_falling_competitor_ai_gap'],
                );
            }

            if ($aiGap instanceof MarketingInsight && ! ($conflict instanceof MarketingInsight)) {
                $recommendations[] = $this->recommendation(
                    key: 'opportunity:ai-visibility:'.(string) $page['id'],
                    type: 'ai_visibility_opportunity',
                    title: 'Strengthen AI visibility for the affected topic',
                    summary: 'The page has weak AI visibility and should be made easier for answer engines to cite and summarize.',
                    insights: [$aiGap],
                    actions: [
                        'Add concise answer sections, entity-rich subheadings, and citation-worthy proof.',
                        'Align the page topic with market-pack terminology where relevant.',
                    ],
                    impact: $aiGap->severity,
                    risk: 45,
                    opportunity: 82,
                    metadata: ['reasoning_pattern' => 'ai_visibility_gap'],
                );
            }

            if ($searchGap instanceof MarketingInsight) {
                $recommendations[] = $this->recommendation(
                    key: 'opportunity:search-visibility:'.(string) $page['id'],
                    type: 'search_visibility_opportunity',
                    title: 'Capture search demand already implied by the evidence',
                    summary: 'The page has demand or authority signals, but search visibility remains below the configured threshold.',
                    insights: [$searchGap],
                    actions: [
                        'Refresh title, headings, and internal links around the highest-confidence topic.',
                        'Add a supporting content piece if the current page cannot cover the intent fully.',
                    ],
                    impact: $searchGap->severity,
                    risk: 40,
                    opportunity: 80,
                    metadata: ['reasoning_pattern' => 'search_visibility_under_capture'],
                );
            }

            if ($prAmplification instanceof MarketingInsight) {
                $recommendations[] = $this->recommendation(
                    key: 'opportunity:earned-media-amplification:'.(string) $page['id'],
                    type: 'earned_media_opportunity',
                    title: 'Turn PR value into visibility momentum',
                    summary: 'The page has strong PR value that can reinforce search, social, and answer-engine visibility.',
                    insights: [$prAmplification],
                    actions: [
                        'Repurpose the strongest PR proof into owned content updates and social posts.',
                        'Pitch a follow-up earned-media angle that strengthens the same topic and entities.',
                    ],
                    impact: $prAmplification->severity,
                    risk: 35,
                    opportunity: 78,
                    metadata: ['reasoning_pattern' => 'pr_value_visibility_gap'],
                );
            }
        }

        foreach ($this->momentumInsights($insights) as $insight) {
            $recommendations[] = $this->recommendation(
                key: 'opportunity:scale-momentum:'.$insight->key,
                type: 'momentum_opportunity',
                title: 'Scale the strongest momentum signal',
                summary: $insight->summary,
                insights: [$insight],
                actions: [
                    'Protect the source of momentum and expand adjacent topic or channel coverage.',
                    'Use the next report or scheduled briefing to monitor whether the momentum persists.',
                ],
                impact: $insight->severity,
                risk: 25,
                opportunity: 70,
                metadata: ['reasoning_pattern' => 'performance_momentum'],
            );
        }

        return $recommendations;
    }

    /**
     * @param  array<int, MarketingInsight>  $insights
     * @param  array<int, string>  $actions
     * @param  array<string, mixed>  $metadata
     */
    private function recommendation(
        string $key,
        string $type,
        string $title,
        string $summary,
        array $insights,
        array $actions,
        float $impact,
        float $risk,
        float $opportunity,
        array $metadata = [],
    ): MarketingRecommendation {
        $confidence = $this->confidence($insights);

        return new MarketingRecommendation(
            key: $key,
            type: $type,
            title: $title,
            summary: $summary,
            priority: $this->priority->score($impact, $confidence, $risk, $opportunity),
            confidence: $confidence,
            evidence: MarketingEvidence::merge(...array_map(fn (MarketingInsight $insight): MarketingEvidence => $insight->evidence, $insights)),
            recommendedActions: $actions,
            supportingInsightKeys: collect($insights)->pluck('key')->values()->all(),
            affectedPages: $this->affected($insights, 'affectedPages', 'id'),
            affectedTopics: $this->affected($insights, 'affectedTopics'),
            affectedChannels: $this->affected($insights, 'affectedChannels'),
            affectedCompetitors: $this->affected($insights, 'affectedCompetitors'),
            marketPackContext: $this->marketPackContext($insights),
            metadata: $metadata + [
                'engine' => 'opportunity_engine',
                'automatic_execution' => false,
            ],
        );
    }

    /**
     * @param  array<int, MarketingInsight>  $insights
     * @return array<int, MarketingInsight>
     */
    private function pageInsights(array $insights, string $pageId): array
    {
        return collect($insights)
            ->filter(fn (MarketingInsight $insight): bool => collect($insight->affectedPages)->contains(fn (array $page): bool => (string) ($page['id'] ?? '') === $pageId))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, MarketingInsight>  $insights
     */
    private function firstByKeyPart(array $insights, string $keyPart): ?MarketingInsight
    {
        return collect($insights)->first(fn (MarketingInsight $insight): bool => str_contains($insight->key, $keyPart));
    }

    /**
     * @param  array<int, MarketingInsight>  $insights
     * @return array<int, MarketingInsight>
     */
    private function momentumInsights(array $insights): array
    {
        return collect($insights)
            ->filter(fn (MarketingInsight $insight): bool => $insight->type === 'opportunity')
            ->filter(fn (MarketingInsight $insight): bool => in_array((string) data_get($insight->metadata, 'signal_type'), [
                'organic_growth',
                'topic_momentum',
                'channel_momentum',
                'market_pack_momentum',
                'performance_opportunity',
            ], true))
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, MarketingInsight>  $insights
     */
    private function confidence(array $insights): float
    {
        return round((float) collect($insights)->avg(fn (MarketingInsight $insight): float => $insight->confidence), 4);
    }

    /**
     * @param  array<int, MarketingInsight>  $insights
     */
    private function maxSeverity(array $insights): int
    {
        return (int) collect($insights)->max(fn (MarketingInsight $insight): int => $insight->severity);
    }

    /**
     * @param  array<int, MarketingInsight>  $insights
     * @return array<int, mixed>
     */
    private function affected(array $insights, string $property, ?string $uniqueKey = null): array
    {
        $values = collect($insights)->flatMap(fn (MarketingInsight $insight): array => (array) $insight->{$property});

        if ($uniqueKey) {
            return $values->unique(fn (mixed $value): string => (string) data_get($value, $uniqueKey))->values()->all();
        }

        return $values->filter()->unique()->values()->all();
    }

    /**
     * @param  array<int, MarketingInsight>  $insights
     * @return array<string, mixed>
     */
    private function marketPackContext(array $insights): array
    {
        return collect($insights)
            ->pluck('marketPackContext')
            ->filter()
            ->first() ?: [];
    }
}
