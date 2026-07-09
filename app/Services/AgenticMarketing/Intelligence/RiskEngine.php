<?php

namespace App\Services\AgenticMarketing\Intelligence;

class RiskEngine
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
            $lowScore = $this->firstByKeyPart($pageInsights, 'low-intelligence-score-v2');

            if ($conflict instanceof MarketingInsight) {
                $recommendations[] = $this->recommendation(
                    key: 'risk:engagement-quality:'.(string) $page['id'],
                    type: 'performance_risk',
                    title: 'Fix engagement quality before traffic compounds the gap',
                    summary: 'Traffic is rising while engagement falls, which can weaken conversion quality and future visibility.',
                    insights: [$conflict],
                    actions: [
                        'Review the affected article for intent mismatch, weak intro promise, outdated sections, and missing conversion paths.',
                        'Improve the article with clearer answer structure, stronger internal links, and refreshed examples.',
                        'Use the same period evidence again after the update to confirm engagement recovery.',
                    ],
                    impact: $conflict->severity,
                    risk: 82,
                    opportunity: 55,
                    metadata: ['reasoning_pattern' => 'traffic_growth_plus_engagement_decline'],
                );
            }

            if ($competitor instanceof MarketingInsight && $aiGap instanceof MarketingInsight) {
                $recommendations[] = $this->recommendation(
                    key: 'risk:competitor-ai-pressure:'.(string) $page['id'],
                    type: 'competitive_risk',
                    title: 'Counter competitor pressure in AI-visible answers',
                    summary: 'Competitor visibility pressure is high while AI visibility is weak for the affected page and topic.',
                    insights: [$competitor, $aiGap],
                    actions: [
                        'Strengthen answer-ready sections with direct claims, proof points, and citations.',
                        'Compare competitor framing against the affected topic and close missing proof gaps.',
                        'Prepare a PR or earned-media angle that reinforces the same entity and topic associations.',
                    ],
                    impact: max($competitor->severity, $aiGap->severity),
                    risk: 90,
                    opportunity: 65,
                    metadata: ['reasoning_pattern' => 'competitor_pressure_plus_ai_visibility_gap'],
                );
            }

            if ($lowScore instanceof MarketingInsight) {
                $recommendations[] = $this->recommendation(
                    key: 'risk:low-score-v2:'.(string) $page['id'],
                    type: 'score_risk',
                    title: 'Investigate the low Intelligence Score v2 inputs',
                    summary: 'The versioned intelligence score is low enough to warrant a page-level review before broader campaign work.',
                    insights: [$lowScore],
                    actions: [
                        'Open the v2 score explanation and inspect unavailable or low-confidence components.',
                        'Prioritize the lowest available components before increasing distribution spend.',
                    ],
                    impact: $lowScore->severity,
                    risk: 70,
                    opportunity: 45,
                    metadata: ['reasoning_pattern' => 'low_intelligence_score_v2'],
                );
            }
        }

        $missing = collect($insights)->firstWhere('type', 'missing_data');
        if ($missing instanceof MarketingInsight) {
            $recommendations[] = $this->recommendation(
                key: 'risk:measurement-coverage',
                type: 'measurement_risk',
                title: 'Complete measurement coverage before automating decisions',
                summary: 'Missing intelligence inputs reduce confidence, so the next best action is to improve observation coverage.',
                insights: [$missing],
                actions: [
                    'Connect or repair missing canonical observation sources.',
                    'Generate or refresh Intelligence Score v2 for affected pages.',
                    'Run the reasoning layer again after coverage improves.',
                ],
                impact: 50,
                risk: 65,
                opportunity: 40,
                metadata: ['reasoning_pattern' => 'insufficient_data'],
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
                'engine' => 'risk_engine',
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
     */
    private function confidence(array $insights): float
    {
        return round((float) collect($insights)->avg(fn (MarketingInsight $insight): float => $insight->confidence), 4);
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
