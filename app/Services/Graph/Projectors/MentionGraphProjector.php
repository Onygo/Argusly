<?php

namespace App\Services\Graph\Projectors;

use App\Models\Entity;
use App\Models\GraphNode;
use App\Models\Mention;
use App\Services\Graph\GraphProjectionService;

class MentionGraphProjector
{
    public function __construct(private readonly GraphProjectionService $graph) {}

    public function project(Mention $mention): GraphNode
    {
        $node = $this->graph->node($mention, 'mention', $mention->title ?: 'Untitled mention', [
            'url' => $mention->url,
            'author' => $mention->author,
            'sentiment' => $mention->sentiment,
            'impact_score' => $mention->impact_score,
            'published_at' => $mention->published_at?->toIso8601String(),
        ]);

        if ($mention->brand) {
            $this->graph->edge($node, $this->graph->brand($mention->brand), 'mentions', $mention->impact_score, null, ['source' => 'mention.brand'], $mention->brand_id);
        }

        foreach ($mention->entities()->get() as $mentionEntity) {
            $entity = Entity::query()
                ->where('account_id', $mention->account_id)
                ->where('name', $mentionEntity->entity_name)
                ->first();

            if ($entity) {
                $this->graph->edge($node, $this->graph->entities->project($entity), 'mentions', null, null, ['source' => 'mention_entities', 'entity_type' => $mentionEntity->entity_type], $mention->brand_id);
            }
        }

        $this->graph->projectTopics($mention, $node, 'detected_in');

        return $node;
    }
}
