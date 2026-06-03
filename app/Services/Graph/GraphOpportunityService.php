<?php

namespace App\Services\Graph;

use App\Models\Account;
use App\Models\Brand;
use App\Models\GraphEdge;
use App\Models\GraphNode;
use App\Models\Recommendation;
use Illuminate\Support\Collection;

class GraphOpportunityService
{
    public function discover(Account $account, ?Brand $brand): Collection
    {
        return collect()
            ->merge($this->topicsWithoutContent($account, $brand))
            ->merge($this->narrativesWithoutCitations($account, $brand))
            ->merge($this->competitorTopicGaps($account, $brand))
            ->merge($this->creatorsWithoutCampaigns($account, $brand))
            ->map(fn (array $opportunity) => $this->store($account, $brand, $opportunity));
    }

    private function topicsWithoutContent(Account $account, ?Brand $brand): Collection
    {
        return GraphNode::query()
            ->forTenant($account, $brand)
            ->where('node_type', 'topic')
            ->whereDoesntHave('incomingEdges', fn ($query) => $query->where('relationship_type', 'covers')->whereHas('sourceNode', fn ($node) => $node->where('node_type', 'content')))
            ->limit(20)
            ->get()
            ->map(fn (GraphNode $topic) => [
                'title' => "Create content for {$topic->label}",
                'summary' => "The topic {$topic->label} exists in the knowledge graph but has no projected content asset covering it.",
                'recommended_action' => "Create or attach a content asset that covers {$topic->label}.",
                'action_type' => 'create_content',
                'action_payload' => ['topic_id' => $topic->source_id, 'graph_node_id' => $topic->id, 'opportunity_type' => 'topic_without_content'],
            ]);
    }

    private function narrativesWithoutCitations(Account $account, ?Brand $brand): Collection
    {
        return GraphNode::query()
            ->forTenant($account, $brand)
            ->where('node_type', 'narrative')
            ->whereDoesntHave('incomingEdges', fn ($query) => $query->whereIn('relationship_type', ['detected_in', 'mentions']))
            ->limit(20)
            ->get()
            ->map(fn (GraphNode $narrative) => [
                'title' => "Build citations for {$narrative->label}",
                'summary' => "The narrative {$narrative->label} exists but has no mentions or detections connected to it.",
                'recommended_action' => "Create citation and evidence coverage for {$narrative->label}.",
                'action_type' => 'improve_citations',
                'action_payload' => ['narrative_id' => $narrative->source_id, 'graph_node_id' => $narrative->id, 'opportunity_type' => 'narrative_without_citations'],
            ]);
    }

    private function competitorTopicGaps(Account $account, ?Brand $brand): Collection
    {
        if (! $brand) {
            return collect();
        }

        $brandNode = GraphNode::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->where('node_type', 'brand')->where('source_id', $brand->id)->first();

        if (! $brandNode) {
            return collect();
        }

        return GraphEdge::query()
            ->forTenant($account, $brand)
            ->where('relationship_type', 'related_to')
            ->whereHas('sourceNode', fn ($query) => $query->where('node_type', 'competitor'))
            ->whereHas('targetNode', fn ($query) => $query->where('node_type', 'topic'))
            ->with('targetNode')
            ->get()
            ->filter(fn (GraphEdge $edge) => ! GraphEdge::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->where('source_node_id', $brandNode->id)
                ->where('target_node_id', $edge->target_node_id)
                ->where('relationship_type', 'supports')
                ->exists())
            ->map(fn (GraphEdge $edge) => [
                'title' => "Close competitor topic gap: {$edge->targetNode->label}",
                'summary' => "A competitor is connected to {$edge->targetNode->label}, but {$brand->name} is not.",
                'recommended_action' => "Evaluate whether {$brand->name} should support or cover {$edge->targetNode->label}.",
                'action_type' => 'refresh_positioning',
                'action_payload' => ['topic_id' => $edge->targetNode->source_id, 'graph_node_id' => $edge->targetNode->id, 'opportunity_type' => 'competitor_topic_gap'],
            ]);
    }

    private function creatorsWithoutCampaigns(Account $account, ?Brand $brand): Collection
    {
        return GraphNode::query()
            ->forTenant($account, $brand)
            ->where('node_type', 'creator')
            ->whereHas('outgoingEdges', fn ($query) => $query->whereIn('relationship_type', ['targets', 'related_to', 'covers']))
            ->whereDoesntHave('outgoingEdges', fn ($query) => $query->where('relationship_type', 'participates_in')->whereHas('targetNode', fn ($node) => $node->where('node_type', 'campaign')))
            ->limit(20)
            ->get()
            ->map(fn (GraphNode $creator) => [
                'title' => "Connect creator to campaign: {$creator->label}",
                'summary' => "{$creator->label} is connected to a topic but is not connected to an active campaign.",
                'recommended_action' => "Review {$creator->label} for a campaign collaboration.",
                'action_type' => 'launch_campaign',
                'action_payload' => ['creator_node_id' => $creator->id, 'opportunity_type' => 'creator_without_campaign'],
            ]);
    }

    private function store(Account $account, ?Brand $brand, array $attributes): Recommendation
    {
        return Recommendation::query()->updateOrCreate(
            [
                'account_id' => $account->id,
                'brand_id' => $brand?->id,
                'title' => $attributes['title'],
                'signal_id' => null,
            ],
            [
                'summary' => $attributes['summary'],
                'recommended_action' => $attributes['recommended_action'],
                'action_type' => $attributes['action_type'],
                'action_payload' => $attributes['action_payload'],
                'impact_score' => 70,
                'confidence_score' => 75,
                'status' => 'new',
            ],
        );
    }
}
