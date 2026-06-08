<?php

namespace Database\Factories;

use App\Models\CompetitorContentOpportunity;
use App\Models\SiteCompetitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompetitorContentOpportunity>
 */
class CompetitorContentOpportunityFactory extends Factory
{
    protected $model = CompetitorContentOpportunity::class;

    public function definition(): array
    {
        $competitor = SiteCompetitor::query()->with('workspace')->first();
        $topic = 'ai visibility implementation';
        $type = 'implementation_guide';

        return [
            'organization_id' => $competitor?->workspace?->organization_id,
            'workspace_id' => $competitor?->workspace_id,
            'client_site_id' => $competitor?->client_site_id,
            'site_competitor_id' => $competitor?->id,
            'type' => $type,
            'status' => 'open',
            'title' => 'Create an implementation guide for AI visibility implementation',
            'topic' => $topic,
            'query_intent' => 'implementation',
            'funnel_stage' => 'mofu',
            'recommended_format' => 'implementation_guide',
            'priority_score' => 88,
            'confidence_score' => 76,
            'impact_score' => 90,
            'effort_score' => 58,
            'attackable_angle' => 'Win practical intent with a step-by-step guide and answer blocks.',
            'reason' => 'Competitors publish this topic and Argusly has no matching coverage.',
            'competitor_evidence' => [],
            'argusly_coverage' => ['status' => 'missing'],
            'normalized_payload' => ['source' => 'competitor_intelligence'],
            'dedupe_hash' => hash('sha256', (string) $competitor?->workspace_id . '|' . $type . '|' . $topic),
            'last_seen_at' => now(),
        ];
    }
}
