<?php

namespace App\Services\ContentNetwork;

use App\Models\Content;
use App\Models\ContentCluster;
use App\Models\Draft;
use App\Models\LinkOpportunity;
use App\Models\LinkSuggestion;
use App\Models\SeoAudit;
use App\Models\SeoAuditPage;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LinkGraphAnalyzer
{
    /**
     * @param array<string,array<string,mixed>> $contentSignals
     * @return array<string,mixed>
     */
    public function analyzeAndPersist(Workspace $workspace, array $contentSignals = []): array
    {
        $contents = Content::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query): void {
                $query->where('publish_status', 'published')
                    ->orWhere('status', 'published')
                    ->orWhereNotNull('published_url');
            })
            ->where('status', '!=', 'archived')
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'title', 'primary_keyword', 'published_url']);

        if ($contents->isEmpty()) {
            LinkOpportunity::query()->where('workspace_id', $workspace->id)->delete();

            return [
                'opportunities_count' => 0,
                'orphan_content_ids' => [],
                'weakly_connected_content_ids' => [],
            ];
        }

        $contentIds = $contents->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
        $appliedEdges = $this->existingEdges($workspace, $contentIds);
        $internalLinkCounts = $this->latestInternalLinkCounts($workspace, $contentIds);
        [$incomingCounts, $outgoingCounts] = $this->edgeCounts($contentIds, $appliedEdges);

        $orphanIds = [];
        $weakIds = [];
        foreach ($contentIds as $contentId) {
            $internal = (int) ($internalLinkCounts[$contentId] ?? 0);
            $incoming = (int) ($incomingCounts[$contentId] ?? 0);
            $outgoing = (int) ($outgoingCounts[$contentId] ?? 0);

            if (($internal + $incoming + $outgoing) === 0) {
                $orphanIds[] = $contentId;
            } elseif (($incoming + $outgoing) <= 1) {
                $weakIds[] = $contentId;
            }
        }

        $clusters = ContentCluster::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('cluster_score')
            ->get(['id', 'topic_keyword', 'pillar_content_id', 'supporting_content_ids', 'cluster_score']);

        $opportunities = $this->buildOpportunities(
            workspace: $workspace,
            contents: $contents,
            clusters: $clusters,
            contentSignals: $contentSignals,
            existingEdges: $appliedEdges,
            orphanIds: $orphanIds,
        );

        DB::transaction(function () use ($workspace, $opportunities): void {
            LinkOpportunity::query()->where('workspace_id', $workspace->id)->delete();

            foreach ($opportunities as $payload) {
                LinkOpportunity::query()->create($payload);
            }
        });

        return [
            'opportunities_count' => count($opportunities),
            'orphan_content_ids' => array_values($orphanIds),
            'weakly_connected_content_ids' => array_values($weakIds),
        ];
    }

    /**
     * @param array<int,string> $contentIds
     * @return array<string,bool>
     */
    private function existingEdges(Workspace $workspace, array $contentIds): array
    {
        $draftsById = Draft::query()
            ->whereNotNull('content_id')
            ->whereIn('content_id', $contentIds)
            ->get(['id', 'content_id'])
            ->keyBy('id');

        if ($draftsById->isEmpty()) {
            return [];
        }

        $rows = LinkSuggestion::query()
            ->where('source_workspace_id', $workspace->id)
            ->whereIn('status', ['suggested', 'approved', 'applied'])
            ->whereIn('source_article_id', $draftsById->keys()->all())
            ->whereIn('target_article_id', $draftsById->keys()->all())
            ->get(['source_article_id', 'target_article_id']);

        $edges = [];
        foreach ($rows as $row) {
            $sourceContentId = (string) data_get($draftsById->get((string) $row->source_article_id), 'content_id', '');
            $targetContentId = (string) data_get($draftsById->get((string) $row->target_article_id), 'content_id', '');

            if ($sourceContentId === '' || $targetContentId === '' || $sourceContentId === $targetContentId) {
                continue;
            }

            $edges[$sourceContentId . '|' . $targetContentId] = true;
        }

        return $edges;
    }

    /**
     * @param array<int,string> $contentIds
     * @return array<string,int>
     */
    private function latestInternalLinkCounts(Workspace $workspace, array $contentIds): array
    {
        $latestAudit = SeoAudit::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'completed')
            ->latest('finished_at')
            ->first(['id']);

        if (! $latestAudit) {
            return [];
        }

        return SeoAuditPage::query()
            ->where('seo_audit_id', $latestAudit->id)
            ->whereIn('argusly_content_id', $contentIds)
            ->get(['argusly_content_id', 'internal_links_count'])
            ->mapWithKeys(function (SeoAuditPage $page): array {
                return [(string) $page->argusly_content_id => (int) $page->internal_links_count];
            })
            ->all();
    }

    /**
     * @param array<int,string> $contentIds
     * @param array<string,bool> $edges
     * @return array{0:array<string,int>,1:array<string,int>}
     */
    private function edgeCounts(array $contentIds, array $edges): array
    {
        $incoming = array_fill_keys($contentIds, 0);
        $outgoing = array_fill_keys($contentIds, 0);

        foreach (array_keys($edges) as $key) {
            [$sourceId, $targetId] = explode('|', $key);
            if (isset($outgoing[$sourceId])) {
                $outgoing[$sourceId]++;
            }
            if (isset($incoming[$targetId])) {
                $incoming[$targetId]++;
            }
        }

        return [$incoming, $outgoing];
    }

    /**
     * @param Collection<int,Content> $contents
     * @param Collection<int,ContentCluster> $clusters
     * @param array<string,array<string,mixed>> $contentSignals
     * @param array<string,bool> $existingEdges
     * @param array<int,string> $orphanIds
     * @return array<int,array<string,mixed>>
     */
    private function buildOpportunities(
        Workspace $workspace,
        Collection $contents,
        Collection $clusters,
        array $contentSignals,
        array $existingEdges,
        array $orphanIds
    ): array {
        $maxPerSource = (int) config('content_network.analysis.max_opportunities_per_source', 5);
        $contentById = $contents->keyBy('id');
        $orphanLookup = array_fill_keys($orphanIds, true);
        $sourceCounts = [];
        $rows = [];

        foreach ($clusters as $cluster) {
            $clusterContentIds = collect([(string) ($cluster->pillar_content_id ?? '')])
                ->merge((array) ($cluster->supporting_content_ids ?? []))
                ->map(fn (string $id): string => trim($id))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (count($clusterContentIds) < 2) {
                continue;
            }

            foreach ($clusterContentIds as $sourceId) {
                if (! isset($contentById[$sourceId])) {
                    continue;
                }

                $sourceCounts[$sourceId] = (int) ($sourceCounts[$sourceId] ?? 0);
                if ($sourceCounts[$sourceId] >= $maxPerSource) {
                    continue;
                }

                foreach ($clusterContentIds as $targetId) {
                    if ($sourceId === $targetId || ! isset($contentById[$targetId])) {
                        continue;
                    }

                    if ($sourceCounts[$sourceId] >= $maxPerSource) {
                        break;
                    }

                    $edgeKey = $sourceId . '|' . $targetId;
                    if (isset($existingEdges[$edgeKey])) {
                        continue;
                    }

                    if ($this->rowAlreadyExists($rows, $sourceId, $targetId)) {
                        continue;
                    }

                    $relevance = $this->relevanceScore(
                        sourceSignal: $contentSignals[$sourceId] ?? [],
                        targetSignal: $contentSignals[$targetId] ?? [],
                        sourceIsOrphan: isset($orphanLookup[$sourceId]),
                        targetIsPillar: (string) $cluster->pillar_content_id === $targetId,
                    );

                    if ($relevance < 55) {
                        continue;
                    }

                    $target = $contentById[$targetId];
                    $source = $contentById[$sourceId];

                    $rows[] = [
                        'workspace_id' => (string) $workspace->id,
                        'source_content_id' => $sourceId,
                        'target_content_id' => $targetId,
                        'anchor_text_suggestion' => $this->anchorSuggestion($target),
                        'context_snippet' => sprintf(
                            'Reference "%s" when discussing %s.',
                            (string) $target->title,
                            (string) ($cluster->topic_keyword ?: 'this topic')
                        ),
                        'status' => LinkOpportunity::STATUS_SUGGESTED,
                        'relevance_score' => $relevance,
                        'meta' => [
                            'cluster_id' => (string) $cluster->id,
                            'cluster_topic' => (string) $cluster->topic_keyword,
                            'source_title' => (string) $source->title,
                            'target_title' => (string) $target->title,
                            'source_orphan' => isset($orphanLookup[$sourceId]),
                            'target_is_pillar' => (string) $cluster->pillar_content_id === $targetId,
                        ],
                    ];

                    $sourceCounts[$sourceId]++;
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $sourceSignal
     * @param array<string,mixed> $targetSignal
     */
    private function relevanceScore(
        array $sourceSignal,
        array $targetSignal,
        bool $sourceIsOrphan,
        bool $targetIsPillar
    ): float {
        $sourceTerms = collect((array) ($sourceSignal['terms'] ?? []))
            ->map(fn (string $term): string => strtolower(trim($term)))
            ->filter()
            ->unique()
            ->values();

        $targetTerms = collect((array) ($targetSignal['terms'] ?? []))
            ->map(fn (string $term): string => strtolower(trim($term)))
            ->filter()
            ->unique()
            ->values();

        $overlap = $sourceTerms->intersect($targetTerms)->count();
        $denominator = max(1, min($sourceTerms->count(), $targetTerms->count()));
        $overlapRatio = $overlap / $denominator;

        $score = 45 + ($overlapRatio * 35);
        if ($sourceIsOrphan) {
            $score += 10;
        }
        if ($targetIsPillar) {
            $score += 8;
        }

        return round((float) max(0, min(100, $score)), 2);
    }

    private function anchorSuggestion(Content $target): string
    {
        $keyword = trim((string) ($target->primary_keyword ?? ''));
        if ($keyword !== '') {
            return $keyword;
        }

        return Str::limit(trim((string) ($target->title ?? 'related article')), 80, '');
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function rowAlreadyExists(array $rows, string $sourceId, string $targetId): bool
    {
        foreach ($rows as $row) {
            if ((string) ($row['source_content_id'] ?? '') === $sourceId
                && (string) ($row['target_content_id'] ?? '') === $targetId) {
                return true;
            }
        }

        return false;
    }
}
