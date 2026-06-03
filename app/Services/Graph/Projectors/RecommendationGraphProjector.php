<?php

namespace App\Services\Graph\Projectors;

use App\Models\GraphNode;
use App\Models\Narrative;
use App\Models\Recommendation;
use App\Models\Topic;
use App\Services\Graph\GraphProjectionService;

class RecommendationGraphProjector
{
    public function __construct(private readonly GraphProjectionService $graph) {}

    public function project(Recommendation $recommendation): GraphNode
    {
        $node = $this->graph->node($recommendation, 'recommendation', $recommendation->title, [
            'status' => $recommendation->status,
            'action_type' => $recommendation->action_type,
            'impact_score' => $recommendation->impact_score,
            'confidence_score' => $recommendation->confidence_score,
        ]);

        $payload = $recommendation->action_payload ?? [];

        if ($topic = Topic::query()->where('account_id', $recommendation->account_id)->find($payload['topic_id'] ?? null)) {
            $this->graph->edge($node, $this->graph->topics->project($topic), 'recommended_by', $recommendation->impact_score, $recommendation->confidence_score, ['source' => 'recommendation.action_payload'], $recommendation->brand_id);
        }

        if ($narrative = Narrative::query()->where('account_id', $recommendation->account_id)->find($payload['narrative_id'] ?? null)) {
            $this->graph->edge($node, $this->graph->narratives->project($narrative), 'recommended_by', $recommendation->impact_score, $recommendation->confidence_score, ['source' => 'recommendation.action_payload'], $recommendation->brand_id);
        }

        $this->graph->projectTopics($recommendation, $node, 'recommended_by');

        return $node;
    }
}
