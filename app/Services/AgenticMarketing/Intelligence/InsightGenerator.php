<?php

namespace App\Services\AgenticMarketing\Intelligence;

use App\Services\PerformanceIntelligence\PerformanceSignal;
use App\Services\PerformanceIntelligence\PerformanceTrend;
use Illuminate\Support\Str;

class InsightGenerator
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<int, MarketingInsight>
     */
    public function generate(array $context): array
    {
        $insights = [];

        foreach ((array) ($context['pages'] ?? []) as $page) {
            $insights = array_merge($insights, $this->pageInsights($page, (array) ($context['market_pack_context'] ?? [])));
        }

        foreach ((array) data_get($context, 'performance.signals', []) as $signal) {
            if ($signal instanceof PerformanceSignal) {
                $insights[] = $this->signalInsight($signal, (array) ($context['market_pack_context'] ?? []));
            }
        }

        if ((array) ($context['missing_data'] ?? []) !== []) {
            $insights[] = new MarketingInsight(
                key: 'missing-data:'.hash('sha1', implode('|', (array) $context['missing_data'])),
                type: 'missing_data',
                title: 'Measurement coverage is incomplete',
                summary: 'Some intelligence inputs are unavailable, so recommendations include lower confidence where evidence is thin.',
                direction: 'insufficient_data',
                severity: 50,
                confidence: 0.35,
                evidence: $context['evidence'] instanceof MarketingEvidence ? $context['evidence'] : MarketingEvidence::empty(),
                marketPackContext: (array) ($context['market_pack_context'] ?? []),
                metadata: ['missing_data' => (array) $context['missing_data']],
            );
        }

        return collect($insights)
            ->filter(fn (?MarketingInsight $insight): bool => $insight instanceof MarketingInsight)
            ->unique(fn (MarketingInsight $insight): string => $insight->key)
            ->sortByDesc(fn (MarketingInsight $insight): int => $insight->severity)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $marketPackContext
     * @return array<int, MarketingInsight>
     */
    private function pageInsights(array $page, array $marketPackContext): array
    {
        $insights = [];
        $thresholds = $this->thresholds();
        $traffic = $page['traffic_trend'] ?? null;
        $engagement = $page['engagement_trend'] ?? null;
        $trafficGrowth = $traffic instanceof PerformanceTrend && $traffic->direction === 'growth'
            && (float) ($traffic->growthPercent ?? 0) >= (float) $thresholds['strong_growth_percent'];
        $engagementDecline = $engagement instanceof PerformanceTrend && $engagement->direction === 'decline'
            && (float) ($engagement->growthPercent ?? 0) <= (float) $thresholds['meaningful_decline_percent'];
        $competitorPressure = is_numeric($page['competitor_pressure'] ?? null) ? (float) $page['competitor_pressure'] : null;
        $searchVisibility = is_numeric($page['search_visibility'] ?? null) ? (float) $page['search_visibility'] : null;
        $aiVisibility = is_numeric($page['ai_visibility'] ?? null) ? (float) $page['ai_visibility'] : null;
        $prValue = is_numeric($page['pr_value'] ?? null) ? (float) $page['pr_value'] : null;
        $score = is_numeric($page['intelligence_score'] ?? null) ? (float) $page['intelligence_score'] : null;
        $competitorHigh = $competitorPressure !== null && $competitorPressure >= (float) $thresholds['high_competitor_pressure'];
        $searchWeak = $searchVisibility !== null && $searchVisibility < (float) $thresholds['weak_search_visibility'];
        $aiWeak = $aiVisibility !== null && $aiVisibility < (float) $thresholds['weak_ai_visibility'];
        $prStrong = $prValue !== null && $prValue >= (float) $thresholds['strong_pr_value'];
        $scoreLow = $score !== null && $score < (float) $thresholds['low_intelligence_score'];
        $pageRef = $this->pageRef($page);
        $topics = (array) ($page['topics'] ?? []);
        $channels = (array) ($page['channels'] ?? []);
        $competitors = (array) ($page['competitors'] ?? []);
        $marketContext = array_replace($marketPackContext, (array) ($page['market_pack_context'] ?? []));
        $evidence = $page['evidence'] instanceof MarketingEvidence ? $page['evidence'] : MarketingEvidence::empty();

        if ($trafficGrowth && $engagementDecline) {
            $insights[] = new MarketingInsight(
                key: 'page-conflict:traffic-growth-engagement-decline:'.(string) $page['id'],
                type: 'conflicting_signals',
                title: 'Traffic is rising while engagement is falling',
                summary: $pageRef['title'].' is attracting more visits, but engagement is declining in the same period.',
                direction: 'mixed',
                severity: $this->severity([
                    abs((float) $traffic->growthPercent),
                    abs((float) $engagement->growthPercent),
                    $scoreLow ? 80 : 0,
                ]),
                confidence: $this->confidence($traffic->confidence, $engagement->confidence),
                evidence: $evidence,
                affectedPages: [$pageRef],
                affectedTopics: $topics,
                affectedChannels: $channels,
                affectedCompetitors: $competitors,
                marketPackContext: $marketContext,
                metadata: [
                    'traffic_growth_percent' => $traffic->growthPercent,
                    'engagement_growth_percent' => $engagement->growthPercent,
                ],
            );
        }

        if ($competitorHigh) {
            $insights[] = new MarketingInsight(
                key: 'page-risk:competitor-pressure:'.(string) $page['id'],
                type: 'risk',
                title: 'Competitor visibility pressure is high',
                summary: $pageRef['title'].' has competitor evidence strong enough to threaten visibility or conversion momentum.',
                direction: 'pressure',
                severity: $this->severity([$competitorPressure]),
                confidence: 0.82,
                evidence: $evidence,
                affectedPages: [$pageRef],
                affectedTopics: $topics,
                affectedChannels: $channels,
                affectedCompetitors: $competitors,
                marketPackContext: $marketContext,
                metadata: ['competitor_pressure' => $competitorPressure],
            );
        }

        if ($aiWeak) {
            $insights[] = new MarketingInsight(
                key: 'page-opportunity:ai-visibility-gap:'.(string) $page['id'],
                type: 'opportunity',
                title: 'AI visibility is weak',
                summary: $pageRef['title'].' has weak answer-engine visibility compared with the available page and performance evidence.',
                direction: 'gap',
                severity: $this->severity([100 - $aiVisibility, $competitorHigh ? $competitorPressure : 0]),
                confidence: 0.78,
                evidence: $evidence,
                affectedPages: [$pageRef],
                affectedTopics: $topics,
                affectedChannels: $channels,
                affectedCompetitors: $competitors,
                marketPackContext: $marketContext,
                metadata: ['ai_visibility' => $aiVisibility],
            );
        }

        if ($searchWeak && ($trafficGrowth || $prStrong)) {
            $insights[] = new MarketingInsight(
                key: 'page-opportunity:search-visibility-gap:'.(string) $page['id'],
                type: 'opportunity',
                title: 'Search visibility is under-capturing demand',
                summary: $pageRef['title'].' has demand or authority signals, but search visibility remains weak.',
                direction: 'gap',
                severity: $this->severity([100 - $searchVisibility, $prStrong ? $prValue : 0]),
                confidence: 0.76,
                evidence: $evidence,
                affectedPages: [$pageRef],
                affectedTopics: $topics,
                affectedChannels: $channels,
                affectedCompetitors: $competitors,
                marketPackContext: $marketContext,
                metadata: [
                    'search_visibility' => $searchVisibility,
                    'pr_value' => $prValue,
                ],
            );
        }

        if ($scoreLow) {
            $insights[] = new MarketingInsight(
                key: 'page-risk:low-intelligence-score-v2:'.(string) $page['id'],
                type: 'risk',
                title: 'Intelligence Score v2 is low',
                summary: $pageRef['title'].' has a low versioned intelligence score for the current evidence period.',
                direction: 'decline',
                severity: $this->severity([100 - $score]),
                confidence: 0.8,
                evidence: $evidence,
                affectedPages: [$pageRef],
                affectedTopics: $topics,
                affectedChannels: $channels,
                affectedCompetitors: $competitors,
                marketPackContext: $marketContext,
                metadata: ['intelligence_score_v2' => $score],
            );
        }

        if ($prStrong && ($aiWeak || $searchWeak)) {
            $insights[] = new MarketingInsight(
                key: 'page-opportunity:amplify-earned-media:'.(string) $page['id'],
                type: 'opportunity',
                title: 'PR value can support visibility gains',
                summary: $pageRef['title'].' has strong PR value that can be reused to improve search, social, or AI visibility.',
                direction: 'growth',
                severity: $this->severity([$prValue, $aiWeak ? 100 - $aiVisibility : 0, $searchWeak ? 100 - $searchVisibility : 0]),
                confidence: 0.74,
                evidence: $evidence,
                affectedPages: [$pageRef],
                affectedTopics: $topics,
                affectedChannels: $channels,
                affectedCompetitors: $competitors,
                marketPackContext: $marketContext,
                metadata: ['pr_value' => $prValue],
            );
        }

        return $insights;
    }

    private function signalInsight(PerformanceSignal $signal, array $marketPackContext): MarketingInsight
    {
        $isRisk = $signal->type === 'performance_risk' || $signal->direction === 'decline';
        $growth = abs((float) ($signal->metadata['growth_percent'] ?? 0));

        return new MarketingInsight(
            key: 'performance-signal:'.$signal->key,
            type: $isRisk ? 'risk' : 'opportunity',
            title: Str::headline(str_replace('_', ' ', $signal->type)),
            summary: $signal->explanation,
            direction: $signal->direction,
            severity: $this->severity([$growth]),
            confidence: $this->confidence($signal->confidence),
            evidence: new MarketingEvidence(
                marketingObservationIds: $signal->observationIds,
                performanceSignalKeys: [$signal->key],
                sourceMetrics: ['performance_signal' => [$signal->key => $signal->sourceMetrics]]
            ),
            affectedTopics: $signal->subjectType === 'topic' ? [$signal->subjectName ?: $signal->subjectKey] : [],
            affectedChannels: $signal->subjectType === 'channel' ? [$signal->subjectName ?: $signal->subjectKey] : [],
            marketPackContext: $signal->subjectType === 'market_pack'
                ? array_replace($marketPackContext, ['key' => $signal->subjectKey, 'name' => $signal->subjectName])
                : $marketPackContext,
            metadata: [
                'signal_type' => $signal->type,
                'subject_type' => $signal->subjectType,
                'subject_key' => $signal->subjectKey,
                'metric_key' => $signal->metricKey,
                'growth_percent' => $signal->metadata['growth_percent'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{id:string,title:string,url:string}
     */
    private function pageRef(array $page): array
    {
        return [
            'id' => (string) ($page['id'] ?? ''),
            'title' => (string) ($page['title'] ?? 'Untitled page'),
            'url' => (string) ($page['url'] ?? ''),
        ];
    }

    /**
     * @param  array<int, float|int|null>  $values
     */
    private function severity(array $values): int
    {
        $value = collect($values)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => max(0.0, min(100.0, (float) $value)))
            ->avg();

        return (int) round(max(1.0, min(100.0, (float) ($value ?? 1))));
    }

    private function confidence(?float ...$values): float
    {
        $confidence = collect($values)
            ->filter(fn (?float $value): bool => $value !== null)
            ->map(fn (float $value): float => $value > 1 ? $value / 100 : $value)
            ->avg();

        return round(max(0.0, min(1.0, (float) ($confidence ?? 0.5))), 4);
    }

    /**
     * @return array<string, float|int>
     */
    private function thresholds(): array
    {
        return array_replace([
            'strong_growth_percent' => 20,
            'meaningful_decline_percent' => -10,
            'weak_search_visibility' => 50,
            'weak_ai_visibility' => 50,
            'high_competitor_pressure' => 60,
            'strong_pr_value' => 70,
            'low_intelligence_score' => 55,
        ], (array) config('argusly.agentic_marketing_intelligence.thresholds', []));
    }
}
