<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingObjective;
use App\Models\ContentCluster;

class ContentNetworkGapOpportunityDetector implements AgenticMarketingOpportunityDetector
{
    public function detect(AgenticMarketingObjective $objective): array
    {
        if (! $objective->workspace_id) {
            return [];
        }

        return ContentCluster::query()
            ->where('workspace_id', $objective->workspace_id)
            ->where(function ($query): void {
                $query->whereNull('pillar_content_id')
                    ->orWhere('cluster_score', '<', 60);
            })
            ->orderBy('cluster_score')
            ->limit(50)
            ->get()
            ->flatMap(fn (ContentCluster $cluster): array => $this->opportunities($cluster))
            ->values()
            ->all();
    }

    /**
     * @return array<int,DetectedOpportunity>
     */
    private function opportunities(ContentCluster $cluster): array
    {
        $topic = trim((string) ($cluster->topic_keyword ?: $cluster->name ?: 'topic'));
        $supportingCount = collect((array) ($cluster->supporting_content_ids ?? []))->filter()->count();
        $score = (float) ($cluster->cluster_score ?? 0);
        $opportunities = [];

        if (! $cluster->pillar_content_id) {
            $opportunities[] = new DetectedOpportunity(
                title: 'Create pillar content for ' . $topic,
                type: AgenticMarketingOpportunityType::NewArticle,
                priorityScore: max(62, min(92, 82 - (int) round($score / 4))),
                payload: [
                    'detector' => 'content_network_gaps',
                    'signals' => [
                        'gap_type' => 'missing_pillar',
                        'cluster_id' => (string) $cluster->id,
                        'cluster_name' => (string) $cluster->name,
                        'topic_keyword' => $topic,
                        'cluster_score' => $score,
                        'supporting_content_count' => $supportingCount,
                    ],
                ],
            );
        }

        if ($score < 60 || $supportingCount < 2) {
            $opportunities[] = new DetectedOpportunity(
                title: 'Strengthen topic coverage for ' . $topic,
                type: AgenticMarketingOpportunityType::ContentNetwork,
                priorityScore: max(54, min(88, 76 - (int) round($score / 5) + max(0, 2 - $supportingCount) * 6)),
                payload: [
                    'detector' => 'content_network_gaps',
                    'signals' => [
                        'gap_type' => 'weak_cluster_coverage',
                        'cluster_id' => (string) $cluster->id,
                        'cluster_name' => (string) $cluster->name,
                        'topic_keyword' => $topic,
                        'cluster_score' => $score,
                        'supporting_content_count' => $supportingCount,
                    ],
                ],
            );
        }

        return $opportunities;
    }
}
