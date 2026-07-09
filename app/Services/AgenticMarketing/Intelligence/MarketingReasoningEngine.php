<?php

namespace App\Services\AgenticMarketing\Intelligence;

use App\Models\ClientSite;
use App\Models\MarketingObservation;
use App\Models\Workspace;
use App\Support\Intelligence\TimeWindowPreset;
use App\Support\Intelligence\TimeWindowResolver;
use Carbon\CarbonImmutable;

class MarketingReasoningEngine
{
    public function __construct(
        private readonly EvidenceCollector $evidenceCollector,
        private readonly InsightGenerator $insights,
        private readonly RecommendationEngine $recommendations,
        private readonly TimeWindowResolver $timeWindows,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function reason(Workspace $workspace, ?ClientSite $clientSite = null, array $options = []): ReasoningSnapshot
    {
        $granularity = (string) ($options['granularity'] ?? MarketingObservation::GRANULARITY_DAILY);
        $window = isset($options['from'])
            ? $this->timeWindows->resolve(TimeWindowPreset::CUSTOM_RANGE, [
                'from' => $options['from'],
                'to' => $options['to'] ?? now(),
                'granularity' => $granularity,
                'timezone' => $options['timezone'] ?? null,
            ], $workspace, $clientSite)
            : $this->timeWindows->resolve(TimeWindowPreset::ROLLING, [
                'to' => $options['to'] ?? now(),
                'periods' => $this->lookbackDays(),
                'granularity' => $granularity,
                'timezone' => $options['timezone'] ?? null,
            ], $workspace, $clientSite);
        $periodStart = $window->start;
        $periodEnd = $window->end;
        $marketPackKey = trim((string) ($options['market_pack_key'] ?? '')) ?: null;
        $context = $this->evidenceCollector->collect($workspace, $clientSite, $periodStart, $periodEnd, $granularity, $marketPackKey);
        $insights = $this->insights->generate($context);
        $recommendations = $this->recommendations->generate($context, $insights);
        $evidence = MarketingEvidence::merge(
            $context['evidence'] instanceof MarketingEvidence ? $context['evidence'] : MarketingEvidence::empty(),
            ...array_map(fn (MarketingInsight $insight): MarketingEvidence => $insight->evidence, $insights),
            ...array_map(fn (MarketingRecommendation $recommendation): MarketingEvidence => $recommendation->evidence, $recommendations),
        );

        return new ReasoningSnapshot(
            workspaceId: (string) $workspace->id,
            clientSiteId: $clientSite?->id ? (string) $clientSite->id : null,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            granularity: $granularity,
            generatedAt: CarbonImmutable::now(),
            modelKey: $this->modelKey(),
            modelVersion: $this->modelVersion(),
            insights: $insights,
            recommendations: $recommendations,
            evidence: $evidence,
            marketPackContext: (array) ($context['market_pack_context'] ?? []),
            missingData: (array) ($context['missing_data'] ?? []),
            metadata: [
                'phase' => 'agentic_marketing_intelligence',
                'deterministic' => true,
                'llm_dependency' => false,
                'reports_count' => count($context['reports'] ?? []),
                'scheduled_briefings_count' => count($context['scheduled_briefings'] ?? []),
                'insights_count' => count($insights),
                'recommendations_count' => count($recommendations),
            ],
        );
    }

    private function lookbackDays(): int
    {
        return max(1, (int) config('argusly.agentic_marketing_intelligence.lookback_days', 30));
    }

    private function modelKey(): string
    {
        return (string) config('argusly.agentic_marketing_intelligence.model_key', 'argusly_agentic_marketing_intelligence');
    }

    private function modelVersion(): string
    {
        return (string) config('argusly.agentic_marketing_intelligence.model_version', 'v1');
    }

}
