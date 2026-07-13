<?php

namespace App\Services\BrandGrowthPlanning;

use App\Enums\BrandGrowthFindingType;
use App\Enums\BrandGrowthPlanReviewState;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\BrandGrowthPlanFinding;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;
use App\Services\OpportunityIntelligence\OpportunitySignalIngestor;
use App\Services\OpportunityIntelligence\OpportunitySignalPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use RuntimeException;

class BrandGrowthFindingPromotionService
{
    public function __construct(
        private readonly OpportunitySignalIngestor $signals,
        private readonly OpportunityIntelligenceEngine $engine,
    ) {
    }

    public function promote(BrandGrowthPlanFinding $finding, User $user): Opportunity
    {
        $finding->loadMissing(['plan.workspace', 'opportunity']);

        if (! $finding->isApproved()) {
            throw new AuthorizationException('Only approved Brand Growth findings can be promoted.');
        }

        if ($finding->opportunity instanceof Opportunity) {
            return $finding->opportunity;
        }

        $workspace = $finding->plan?->workspace;

        if (! $workspace) {
            throw new RuntimeException('The Brand Growth finding is missing workspace context.');
        }

        $type = $finding->type instanceof BrandGrowthFindingType
            ? $finding->type
            : BrandGrowthFindingType::tryFrom((string) $finding->type) ?? BrandGrowthFindingType::CONTENT_GAP;
        $category = $this->category($type);
        $topic = $this->topic($finding);
        $signal = $this->signals->ingest($workspace, new OpportunitySignalPayload(
            source: OpportunitySignalSource::BRAND_GROWTH_PLAN,
            category: $category,
            topic: $topic,
            entity: $finding->affected_audience ?: $finding->affected_industry ?: $type->value,
            signalStrength: max((float) $finding->impact_score, (float) $finding->urgency_score, 1),
            confidence: max((float) $finding->confidence_score, 1),
            metrics: [
                'impact_score' => (float) $finding->impact_score,
                'urgency_score' => (float) $finding->urgency_score,
                'confidence_score' => (float) $finding->confidence_score,
                'finding_type' => $type->value,
            ],
            evidence: [
                'summary' => $finding->description,
                'rationale' => $finding->rationale,
                'recommended_action' => $finding->recommended_action,
                'source_references' => $finding->source_references ?? [],
                'source_summary' => $finding->source_summary ?? [],
            ],
            metadata: [
                'source_type' => 'brand_growth_plan_finding',
                'brand_growth_plan_id' => (string) $finding->brand_growth_plan_id,
                'brand_growth_plan_finding_id' => (string) $finding->id,
                'review_state' => BrandGrowthPlanReviewState::APPROVED->value,
                'promoted_by_user_id' => $user->id,
            ],
            clientSiteId: $finding->plan?->client_site_id,
            contentId: $finding->content_id,
            observedAt: $finding->created_at ?: now(),
        ));

        $this->engine->run($workspace);

        $opportunity = $signal->opportunities()
            ->where('opportunities.workspace_id', $workspace->id)
            ->orderByDesc('opportunities.priority_score')
            ->first();

        if (! $opportunity instanceof Opportunity) {
            throw new RuntimeException('The finding was promoted to a signal, but no Opportunity was created.');
        }

        $finding->forceFill([
            'opportunity_id' => (string) $opportunity->id,
            'promoted_at' => now(),
        ])->save();

        return $opportunity;
    }

    private function category(BrandGrowthFindingType $type): OpportunityCategory
    {
        return match ($type) {
            BrandGrowthFindingType::COMPETITOR_THREAT,
            BrandGrowthFindingType::COMPETITOR_OPPORTUNITY => OpportunityCategory::COMPETITOR_MOVEMENT,
            BrandGrowthFindingType::AI_VISIBILITY_GAP,
            BrandGrowthFindingType::SERP_OPPORTUNITY => OpportunityCategory::AI_VISIBILITY_OPPORTUNITY,
            BrandGrowthFindingType::CAMPAIGN_OPPORTUNITY,
            BrandGrowthFindingType::CHANNEL_OPPORTUNITY,
            BrandGrowthFindingType::MEASUREMENT_GAP => OpportunityCategory::ENGAGEMENT_OPPORTUNITY,
            default => OpportunityCategory::CONTENT_GAP,
        };
    }

    private function topic(BrandGrowthPlanFinding $finding): string
    {
        return Str::limit(
            trim((string) ($finding->affected_audience ?: $finding->affected_industry ?: $finding->title ?: 'Brand growth finding')),
            220,
            ''
        );
    }
}
