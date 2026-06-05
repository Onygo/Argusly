<?php

namespace App\Services\ContentNetwork;

use App\Models\Content;
use App\Models\ContentCluster;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class ContentGapDetector
{
    /**
     * @return array{
     *   missing_pillar_pages:array<int,array<string,mixed>>,
     *   missing_support_articles:array<int,array<string,mixed>>,
     *   suggested_missing_articles:array<int,array<string,mixed>>
     * }
     */
    public function detectAndPersist(Workspace $workspace, array $clusterResult, array $graphResult): array
    {
        $clusters = $clusterResult['clusters'] instanceof Collection
            ? $clusterResult['clusters']
            : ContentCluster::query()->where('workspace_id', $workspace->id)->get();

        $contentSignals = is_array($clusterResult['content_signals'] ?? null)
            ? $clusterResult['content_signals']
            : [];

        $orphanIds = collect((array) ($graphResult['orphan_content_ids'] ?? []))
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->values()
            ->all();

        $orphanTopics = collect($orphanIds)
            ->map(fn (string $id): string => (string) data_get($contentSignals, $id . '.topic_keyword', ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $missingPillar = [];
        $missingSupport = [];
        $suggested = [];
        $maxSuggestions = (int) config('content_network.analysis.max_gap_suggestions', 24);

        /** @var ContentCluster $cluster */
        foreach ($clusters as $cluster) {
            $topic = trim((string) ($cluster->topic_keyword ?? 'general topic'));
            $supportingCount = collect((array) ($cluster->supporting_content_ids ?? []))
                ->filter()
                ->count();
            $relatedCount = $supportingCount + ((string) ($cluster->pillar_content_id ?? '') !== '' ? 1 : 0);
            $clusterScore = (float) ($cluster->cluster_score ?? 0);

            if ((string) ($cluster->pillar_content_id ?? '') === '') {
                $missingPillar[] = [
                    'cluster_id' => (string) $cluster->id,
                    'cluster_name' => (string) $cluster->name,
                    'topic_keyword' => $topic,
                    'suggested_title' => sprintf('Complete guide to %s', $topic),
                    'reason' => 'No clear pillar page is mapped for this cluster.',
                ];
            }

            if ($relatedCount < 3) {
                $needed = max(1, 3 - $relatedCount);
                for ($i = 1; $i <= $needed; $i++) {
                    $missingSupport[] = [
                        'cluster_id' => (string) $cluster->id,
                        'cluster_name' => (string) $cluster->name,
                        'topic_keyword' => $topic,
                        'suggested_title' => sprintf('%s: practical use case %d', ucfirst($topic), $i),
                        'reason' => 'Cluster lacks enough supporting articles.',
                    ];
                }
            }

            if ($clusterScore < 55) {
                $suggested[] = [
                    'cluster_id' => (string) $cluster->id,
                    'cluster_name' => (string) $cluster->name,
                    'topic_keyword' => $topic,
                    'suggested_title' => sprintf('Common mistakes in %s and how to avoid them', $topic),
                    'reason' => 'Cluster coverage score is low and can be strengthened.',
                    'priority' => 'high',
                ];
            }
        }

        foreach ($orphanTopics as $topic) {
            if (count($suggested) >= $maxSuggestions) {
                break;
            }

            $suggested[] = [
                'cluster_id' => null,
                'cluster_name' => 'Orphan recovery',
                'topic_keyword' => $topic,
                'suggested_title' => sprintf('How %s connects to your broader strategy', $topic),
                'reason' => 'Orphan topic needs stronger integration via supporting content.',
                'priority' => 'medium',
            ];
        }

        $missingPillar = array_values(array_slice($missingPillar, 0, $maxSuggestions));
        $missingSupport = array_values(array_slice($missingSupport, 0, $maxSuggestions));
        $suggested = array_values(array_slice($suggested, 0, $maxSuggestions));

        $this->persistClusterGapMeta($clusters, $missingPillar, $missingSupport);

        return [
            'missing_pillar_pages' => $missingPillar,
            'missing_support_articles' => $missingSupport,
            'suggested_missing_articles' => $suggested,
        ];
    }

    /**
     * @param Collection<int,ContentCluster> $clusters
     * @param array<int,array<string,mixed>> $missingPillar
     * @param array<int,array<string,mixed>> $missingSupport
     */
    private function persistClusterGapMeta(Collection $clusters, array $missingPillar, array $missingSupport): void
    {
        $pillarsByCluster = collect($missingPillar)->groupBy('cluster_id');
        $supportByCluster = collect($missingSupport)->groupBy('cluster_id');

        foreach ($clusters as $cluster) {
            $clusterId = (string) $cluster->id;
            $meta = is_array($cluster->meta) ? $cluster->meta : [];

            $meta['gap_summary'] = [
                'missing_pillar_count' => (int) $pillarsByCluster->get($clusterId, collect())->count(),
                'missing_support_count' => (int) $supportByCluster->get($clusterId, collect())->count(),
            ];

            $cluster->update(['meta' => $meta]);
        }
    }
}
