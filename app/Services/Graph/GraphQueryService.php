<?php

namespace App\Services\Graph;

use App\Models\Account;
use App\Models\Brand;
use App\Models\GraphEdge;
use App\Models\GraphNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GraphQueryService
{
    public function summary(Account $account, ?Brand $brand): array
    {
        return [
            'nodes' => GraphNode::query()->forTenant($account, $brand)->count(),
            'edges' => GraphEdge::query()->forTenant($account, $brand)->count(),
            'nodeCounts' => GraphNode::query()
                ->forTenant($account, $brand)
                ->select('node_type', DB::raw('COUNT(*) as total'))
                ->groupBy('node_type')
                ->orderByDesc('total')
                ->pluck('total', 'node_type'),
            'edgeCounts' => GraphEdge::query()
                ->forTenant($account, $brand)
                ->select('relationship_type', DB::raw('COUNT(*) as total'))
                ->groupBy('relationship_type')
                ->orderByDesc('total')
                ->pluck('total', 'relationship_type'),
            'topConnected' => $this->topConnected($account, $brand),
            'mostMentionedTopics' => $this->topByType($account, $brand, 'topic', ['mentions', 'detected_in']),
            'mostConnectedCompetitors' => $this->topByType($account, $brand, 'competitor'),
            'mostActiveCreators' => $this->topByType($account, $brand, 'creator'),
            'mostReferencedNarratives' => $this->topByType($account, $brand, 'narrative'),
        ];
    }

    public function dashboard(Account $account, ?Brand $brand): array
    {
        return [
            'health' => $this->summary($account, $brand),
            'topics' => $this->topByType($account, $brand, 'topic', limit: 5),
            'entities' => $this->topByType($account, $brand, 'entity', limit: 5),
            'relationshipGrowth' => GraphEdge::query()
                ->forTenant($account, $brand)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'narrativeCoverage' => [
                'narratives' => GraphNode::query()->forTenant($account, $brand)->where('node_type', 'narrative')->count(),
                'referenced' => $this->topByType($account, $brand, 'narrative')->count(),
            ],
        ];
    }

    public function relatedTopics(Account $account, ?Brand $brand, int $topicNodeId, int $limit = 10): Collection
    {
        return $this->relatedNodes($account, $brand, $topicNodeId, ['topic'], $limit);
    }

    public function relatedCompetitors(Account $account, ?Brand $brand, int $nodeId, int $limit = 10): Collection
    {
        return $this->relatedNodes($account, $brand, $nodeId, ['competitor'], $limit);
    }

    public function creatorsConnectedToTopic(Account $account, ?Brand $brand, int $topicNodeId, int $limit = 10): Collection
    {
        return $this->relatedNodes($account, $brand, $topicNodeId, ['creator'], $limit);
    }

    public function campaignsConnectedToNarrative(Account $account, ?Brand $brand, int $narrativeNodeId, int $limit = 10): Collection
    {
        return $this->relatedNodes($account, $brand, $narrativeNodeId, ['campaign'], $limit);
    }

    public function contentAssetsSupportingTopic(Account $account, ?Brand $brand, int $topicNodeId, int $limit = 10): Collection
    {
        return $this->relatedNodes($account, $brand, $topicNodeId, ['content'], $limit);
    }

    public function mentionsConnectedToCompetitor(Account $account, ?Brand $brand, int $competitorNodeId, int $limit = 10): Collection
    {
        return $this->relatedNodes($account, $brand, $competitorNodeId, ['mention'], $limit);
    }

    public function recommendationsRelatedToNarrative(Account $account, ?Brand $brand, int $narrativeNodeId, int $limit = 10): Collection
    {
        return $this->relatedNodes($account, $brand, $narrativeNodeId, ['recommendation'], $limit);
    }

    public function topConnected(Account $account, ?Brand $brand, int $limit = 10): Collection
    {
        return GraphNode::query()
            ->forTenant($account, $brand)
            ->withCount(['outgoingEdges', 'incomingEdges'])
            ->get()
            ->each(fn (GraphNode $node) => $node->setAttribute('connections_count', $node->outgoing_edges_count + $node->incoming_edges_count))
            ->sortByDesc('connections_count')
            ->take($limit)
            ->values();
    }

    public function topByType(Account $account, ?Brand $brand, string $nodeType, ?array $relationshipTypes = null, int $limit = 10): Collection
    {
        return GraphNode::query()
            ->forTenant($account, $brand)
            ->where('node_type', $nodeType)
            ->withCount([
                'outgoingEdges' => fn ($query) => $relationshipTypes ? $query->whereIn('relationship_type', $relationshipTypes) : $query,
                'incomingEdges' => fn ($query) => $relationshipTypes ? $query->whereIn('relationship_type', $relationshipTypes) : $query,
            ])
            ->get()
            ->each(fn (GraphNode $node) => $node->setAttribute('connections_count', $node->outgoing_edges_count + $node->incoming_edges_count))
            ->sortByDesc('connections_count')
            ->take($limit)
            ->values();
    }

    private function relatedNodes(Account $account, ?Brand $brand, int $nodeId, array $nodeTypes, int $limit): Collection
    {
        $edgeIds = GraphEdge::query()
            ->forTenant($account, $brand)
            ->where(fn (Builder $query) => $query->where('source_node_id', $nodeId)->orWhere('target_node_id', $nodeId))
            ->get()
            ->flatMap(fn (GraphEdge $edge) => [$edge->source_node_id, $edge->target_node_id])
            ->reject(fn (int $id) => $id === $nodeId)
            ->unique()
            ->values();

        return GraphNode::query()
            ->forTenant($account, $brand)
            ->whereIn('id', $edgeIds)
            ->whereIn('node_type', $nodeTypes)
            ->limit($limit)
            ->get();
    }
}
