<?php

namespace App\Services\CampaignClusterEngine;

use Illuminate\Support\Collection;

class CampaignClusterScoringService
{
    /**
     * @return array<string,mixed>
     */
    public function score(CampaignClusterPlan $plan): array
    {
        $items = collect($plan->items);
        $types = $items->pluck('type')->unique();
        $stages = $items->pluck('funnel_stage')->filter()->unique();
        $intents = $items->pluck('search_intent')->filter()->unique();

        $authority = min(100, 30 + $items->sum('authority_contribution') + ($types->contains('pillar_page') ? 10 : 0));
        $topical = min(100, 20 + $items->sum('coverage_contribution') + min(20, $intents->count() * 4));
        $funnel = round(($stages->intersect(['awareness', 'consideration', 'decision', 'retention'])->count() / 4) * 100, 2);
        $aiVisibility = min(100, 35 + ($types->contains('answer_blocks') ? 20 : 0) + ($types->contains('faq_cluster') ? 15 : 0) + ($types->contains('implementation_guide') ? 10 : 0));
        $completeness = round(($authority * 0.3) + ($topical * 0.25) + ($funnel * 0.25) + ($aiVisibility * 0.2), 2);

        return [
            'authority_score' => round($authority, 2),
            'topical_coverage_score' => round($topical, 2),
            'funnel_coverage_score' => $funnel,
            'ai_visibility_score' => round($aiVisibility, 2),
            'completeness_score' => $completeness,
            'funnel_coverage' => $this->funnelCoverage($items),
            'missing_coverage' => $this->missingCoverage($types, $stages),
            'authority_gaps' => $this->authorityGaps($types, $items),
        ];
    }

    private function funnelCoverage(Collection $items): array
    {
        return collect(['awareness', 'consideration', 'decision', 'retention'])
            ->mapWithKeys(fn (string $stage): array => [
                $stage => [
                    'covered' => $items->where('funnel_stage', $stage)->isNotEmpty(),
                    'items' => $items->where('funnel_stage', $stage)->pluck('title')->values()->all(),
                ],
            ])
            ->all();
    }

    private function missingCoverage(Collection $types, Collection $stages): array
    {
        $missing = [];
        foreach (['pillar_page', 'supporting_article', 'comparison_page', 'faq_cluster', 'answer_blocks', 'implementation_guide'] as $type) {
            if (! $types->contains($type)) {
                $missing[] = ['type' => 'format', 'value' => $type, 'severity' => 'medium'];
            }
        }
        foreach (['awareness', 'consideration', 'decision', 'retention'] as $stage) {
            if (! $stages->contains($stage)) {
                $missing[] = ['type' => 'funnel_stage', 'value' => $stage, 'severity' => $stage === 'decision' ? 'high' : 'medium'];
            }
        }

        return $missing;
    }

    private function authorityGaps(Collection $types, Collection $items): array
    {
        $gaps = [];
        if (! $types->contains('pillar_page')) {
            $gaps[] = 'No pillar page exists for the primary entity.';
        }
        if ($items->count() < 6) {
            $gaps[] = 'Cluster needs more supporting assets to feel comprehensive.';
        }
        if (! $types->contains('answer_blocks')) {
            $gaps[] = 'No answer block layer is planned for AI visibility.';
        }

        return $gaps;
    }
}
