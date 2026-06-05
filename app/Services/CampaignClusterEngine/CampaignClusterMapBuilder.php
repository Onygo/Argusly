<?php

namespace App\Services\CampaignClusterEngine;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CampaignClusterMapBuilder
{
    public function visualMap(CampaignClusterPlan $plan, array $scores): array
    {
        $nodes = collect($plan->items)
            ->map(fn (array $item): array => [
                'id' => 'item-'.$item['sequence_order'],
                'label' => $item['title'],
                'type' => $item['type'],
                'stage' => $item['funnel_stage'],
                'score' => round(((float) $item['authority_contribution'] + (float) $item['coverage_contribution']) / 2, 2),
            ])
            ->values()
            ->all();

        $edges = collect($plan->dependencies)
            ->map(fn (array $dependency): array => [
                'from' => 'item-'.$dependency['source_order'],
                'to' => 'item-'.$dependency['target_order'],
                'type' => $dependency['type'],
                'label' => $dependency['anchor_text'],
            ])
            ->values()
            ->all();

        return [
            'topic_relationships' => ['nodes' => $nodes, 'edges' => $edges],
            'missing_coverage' => $scores['missing_coverage'],
            'authority_gaps' => $scores['authority_gaps'],
            'cluster_completeness' => [
                'score' => $scores['completeness_score'],
                'status' => $scores['completeness_score'] >= 85 ? 'strong' : ($scores['completeness_score'] >= 65 ? 'developing' : 'thin'),
            ],
        ];
    }

    public function internalLinkArchitecture(CampaignClusterPlan $plan): array
    {
        return [
            'model' => 'hub_and_spoke',
            'pillar' => data_get($plan->items, '0.title'),
            'rules' => [
                'Every supporting asset links to the pillar with entity-match anchor text.',
                'The pillar links to every comparison, implementation, FAQ, and use-case asset.',
                'Decision-stage pages link laterally to implementation and proof assets.',
            ],
            'dependencies' => $plan->dependencies,
        ];
    }

    public function publishingSequence(CampaignClusterPlan $plan, ?Carbon $startDate = null): array
    {
        $startDate ??= now()->startOfWeek()->addWeek();

        return collect($plan->items)
            ->sortBy('sequence_order')
            ->values()
            ->map(fn (array $item, int $index): array => [
                'order' => $item['sequence_order'],
                'title' => $item['title'],
                'type' => $item['type'],
                'planned_publish_date' => $startDate->copy()->addWeeks($index)->toDateString(),
                'depends_on' => $this->dependsOn($plan->dependencies, $item['sequence_order']),
            ])
            ->all();
    }

    public function timeline(array $sequence): array
    {
        return collect($sequence)
            ->map(fn (array $item): array => [
                'date' => $item['planned_publish_date'],
                'label' => $item['title'],
                'type' => $item['type'],
            ])
            ->all();
    }

    public function localizationStrategy(CampaignClusterPlan $plan, array $companyLocales = []): array
    {
        $locales = collect($companyLocales)->filter()->values()->all() ?: ['en'];

        return [
            'recommended' => count($locales) > 1 || collect($plan->items)->whereIn('type', ['comparison_page', 'implementation_guide'])->isNotEmpty(),
            'priority_locales' => $locales,
            'sequence' => 'Publish source locale first, then localize pillar, comparison, and implementation pages before FAQ variants.',
        ];
    }

    private function dependsOn(array $dependencies, int $order): array
    {
        return collect($dependencies)
            ->where('source_order', $order)
            ->pluck('target_order')
            ->unique()
            ->values()
            ->all();
    }
}
