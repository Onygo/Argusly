<?php

namespace App\Services\AgenticMarketing\StrategicPlanning;

use App\Models\AgenticMarketingOpportunity;
use App\Models\Content;
use Illuminate\Support\Str;

class StrategicPlanningEngine
{
    /**
     * @return array<string,mixed>
     */
    public function clusterProposalForOpportunity(AgenticMarketingOpportunity $opportunity): array
    {
        $opportunity->loadMissing('objective');

        $topic = $this->topic($opportunity);
        $existingTitles = $this->existingTitles($opportunity);
        $missing = $this->missingAssets($topic, $existingTitles);
        $impact = $this->estimatedImpact($opportunity, $missing);

        return [
            'schema' => 'agentic_marketing.strategic_cluster_proposal.v1',
            'proposal_type' => 'content_authority_cluster',
            'topic' => $topic,
            'objective' => [
                'name' => $opportunity->objective?->name,
                'goal' => $opportunity->objective?->goal,
            ],
            'missing' => $missing,
            'estimated_impact' => $impact,
            'priority' => $impact === 'High' ? 1 : ($impact === 'Medium' ? 2 : 3),
            'recommended_sequence' => array_map(
                fn (array $asset, int $index): array => [
                    'order' => $index + 1,
                    'asset_type' => $asset['type'],
                    'title' => $asset['recommended_title'],
                    'reason' => $asset['reason'],
                ],
                $missing,
                array_keys($missing)
            ),
            'strategic_rationale' => $this->rationale($topic, $missing, $impact),
            'next_execution_move' => $missing[0]['type'] ?? 'refresh_existing_authority_page',
        ];
    }

    private function topic(AgenticMarketingOpportunity $opportunity): string
    {
        $topic = trim((string) data_get($opportunity->payload, 'cluster_topic', data_get($opportunity->payload, 'topic', $opportunity->title)));

        if (Str::upper($topic) === 'GEO') {
            return 'AI Visibility';
        }

        return Str::headline($topic !== '' ? $topic : 'AI Visibility');
    }

    /**
     * @return array<int,string>
     */
    private function existingTitles(AgenticMarketingOpportunity $opportunity): array
    {
        $workspaceId = $opportunity->objective?->workspace_id;
        if (! $workspaceId) {
            return [];
        }

        return Content::query()
            ->where('workspace_id', $workspaceId)
            ->pluck('title')
            ->map(fn (mixed $title): string => Str::lower((string) $title))
            ->all();
    }

    /**
     * @param array<int,string> $existingTitles
     * @return array<int,array<string,mixed>>
     */
    private function missingAssets(string $topic, array $existingTitles): array
    {
        $catalog = [
            ['type' => 'glossary', 'title' => $topic.' Glossary', 'reason' => 'Defines core entities and terminology for answer engines.', 'funnel_stage' => 'awareness'],
            ['type' => 'comparison_pages', 'title' => $topic.' Platform Comparison Pages', 'reason' => 'Captures evaluative searches and competitor/category alternatives.', 'funnel_stage' => 'consideration'],
            ['type' => 'faq_hub', 'title' => $topic.' FAQ Hub', 'reason' => 'Consolidates high-intent questions into extractable answers and schema.', 'funnel_stage' => 'awareness'],
            ['type' => 'case_study', 'title' => $topic.' Case Study', 'reason' => 'Adds proof, outcomes, and concrete source context.', 'funnel_stage' => 'decision'],
            ['type' => 'implementation_guide', 'title' => $topic.' Implementation Guide', 'reason' => 'Turns strategic interest into an operational workflow.', 'funnel_stage' => 'consideration'],
            ['type' => 'tooling_comparison', 'title' => $topic.' Tooling Comparison', 'reason' => 'Supports bottom-of-funnel evaluation and procurement questions.', 'funnel_stage' => 'decision'],
            ['type' => 'enterprise_governance_article', 'title' => 'Enterprise '.$topic.' Governance', 'reason' => 'Covers risk, ownership, approval, and operating-model concerns.', 'funnel_stage' => 'decision'],
        ];

        return array_values(array_filter(array_map(function (array $asset) use ($existingTitles): ?array {
            $needle = Str::lower(str_replace('_', ' ', (string) $asset['type']));
            $hasCoverage = collect($existingTitles)->contains(
                fn (string $title): bool => str_contains($title, $needle)
                    || str_contains($title, Str::lower((string) $asset['title']))
            );

            if ($hasCoverage) {
                return null;
            }

            return [
                'type' => $asset['type'],
                'recommended_title' => $asset['title'],
                'reason' => $asset['reason'],
                'funnel_stage' => $asset['funnel_stage'],
                'status' => 'missing',
            ];
        }, $catalog)));
    }

    /**
     * @param array<int,array<string,mixed>> $missing
     */
    private function estimatedImpact(AgenticMarketingOpportunity $opportunity, array $missing): string
    {
        $score = (int) ($opportunity->priority_score ?? 0);

        if ($score >= 80 || count($missing) >= 5) {
            return 'High';
        }

        return $score >= 55 || count($missing) >= 3 ? 'Medium' : 'Low';
    }

    /**
     * @param array<int,array<string,mixed>> $missing
     */
    private function rationale(string $topic, array $missing, string $impact): string
    {
        return sprintf(
            'Build a %s authority cluster because %d strategic asset types are missing. Estimated impact is %s because the cluster can expand entity coverage, internal linking, AI answer readiness, and conversion paths.',
            $topic,
            count($missing),
            Str::lower($impact)
        );
    }
}
