<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Enums\AgenticMarketingOpportunityType;
use App\Enums\ContentDecayRiskLevel;
use App\Enums\ContentLifecycleStatus;
use App\Models\AgenticMarketingObjective;
use App\Models\Content;

class RefreshLifecycleOpportunityDetector implements AgenticMarketingOpportunityDetector
{
    use DetectsObjectiveContent;

    public function detect(AgenticMarketingObjective $objective): array
    {
        return $this->contentQuery($objective, [
            'id',
            'workspace_id',
            'client_site_id',
            'title',
            'language',
            'status',
            'publish_status',
            'updated_at',
            'freshness_score',
            'content_health_score',
            'optimization_opportunity_score',
            'decay_risk_level',
            'lifecycle_stage',
        ])
            ->where(function ($query): void {
                $query->where('lifecycle_stage', ContentLifecycleStatus::REFRESH_NEEDED->value)
                    ->orWhereIn('decay_risk_level', [
                        ContentDecayRiskLevel::HIGH->value,
                        ContentDecayRiskLevel::CRITICAL->value,
                    ])
                    ->orWhere('freshness_score', '<', 60)
                    ->orWhere('content_health_score', '<', 70)
                    ->orWhere('optimization_opportunity_score', '>=', 60)
                    ->orWhereDate('updated_at', '<=', now()->subDays((int) config('content_refresh.thresholds.aging_days', 90)));
            })
            ->orderByDesc('optimization_opportunity_score')
            ->orderBy('freshness_score')
            ->limit(50)
            ->get()
            ->map(fn (Content $content): DetectedOpportunity => $this->opportunity($content))
            ->all();
    }

    private function opportunity(Content $content): DetectedOpportunity
    {
        $stage = $this->stringValue($content->lifecycle_stage);
        $decay = $this->stringValue($content->decay_risk_level);
        $freshness = (int) ($content->freshness_score ?? 0);
        $health = (int) ($content->content_health_score ?? 0);
        $optimization = (int) ($content->optimization_opportunity_score ?? 0);

        $factors = array_values(array_filter([
            $stage === ContentLifecycleStatus::REFRESH_NEEDED->value ? 'refresh_needed_lifecycle_stage' : null,
            in_array($decay, [ContentDecayRiskLevel::HIGH->value, ContentDecayRiskLevel::CRITICAL->value], true) ? 'content_decay_risk' : null,
            $freshness > 0 && $freshness < 60 ? 'low_freshness_score' : null,
            $health > 0 && $health < 70 ? 'low_content_health_score' : null,
            $optimization >= 60 ? 'high_optimization_opportunity_score' : null,
        ]));

        return new DetectedOpportunity(
            title: 'Refresh lifecycle opportunity: ' . (string) $content->title,
            type: AgenticMarketingOpportunityType::Refresh,
            priorityScore: $this->scoreFromSignals(
                48,
                $stage === ContentLifecycleStatus::REFRESH_NEEDED->value ? 20 : 0,
                $decay === ContentDecayRiskLevel::CRITICAL->value ? 18 : 0,
                $decay === ContentDecayRiskLevel::HIGH->value ? 12 : 0,
                $freshness > 0 ? max(0, 60 - $freshness) / 2 : 0,
                $health > 0 ? max(0, 70 - $health) / 3 : 0,
                $optimization >= 60 ? 12 : 0,
            ),
            payload: [
                'detector' => 'refresh_lifecycle',
                'content_id' => (string) $content->id,
                'signals' => [
                    'lifecycle_stage' => $stage,
                    'decay_risk_level' => $decay,
                    'freshness_score' => $freshness,
                    'content_health_score' => $health,
                    'optimization_opportunity_score' => $optimization,
                    'factors' => $factors,
                ],
            ],
            contentId: (string) $content->id,
        );
    }
}
