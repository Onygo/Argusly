<?php

namespace Database\Factories;

use App\Models\CompetitorTopicSignal;
use App\Models\SiteCompetitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompetitorTopicSignal>
 */
class CompetitorTopicSignalFactory extends Factory
{
    protected $model = CompetitorTopicSignal::class;

    public function definition(): array
    {
        $competitor = SiteCompetitor::query()->with('workspace')->first();
        $topic = 'ai visibility implementation';

        return [
            'organization_id' => $competitor?->workspace?->organization_id,
            'workspace_id' => $competitor?->workspace_id,
            'client_site_id' => $competitor?->client_site_id,
            'site_competitor_id' => $competitor?->id,
            'topic' => $topic,
            'topic_hash' => hash('sha256', $topic),
            'competitor_content_count' => 2,
            'publishlayer_content_count' => 0,
            'overlap_score' => 0,
            'opportunity_score' => 92,
            'coverage_status' => 'missing',
            'intent_mix' => ['implementation' => 2],
            'formats' => ['implementation_guide' => 2],
            'entities' => ['AI' => 2],
            'examples' => [],
            'last_seen_at' => now(),
        ];
    }
}
