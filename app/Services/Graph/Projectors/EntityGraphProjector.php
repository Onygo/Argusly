<?php

namespace App\Services\Graph\Projectors;

use App\Models\Entity;
use App\Models\GraphNode;
use App\Services\Graph\GraphProjectionService;

class EntityGraphProjector
{
    public function __construct(private readonly GraphProjectionService $graph) {}

    public function project(Entity $entity): GraphNode
    {
        $nodeType = match ($entity->entity_type) {
            'competitor' => 'competitor',
            'creator', 'journalist' => 'creator',
            'organization' => 'organization',
            default => 'entity',
        };

        $node = $this->graph->node($entity, $nodeType, $entity->name, [
            'slug' => $entity->slug,
            'entity_type' => $entity->entity_type,
            'status' => $entity->status,
            'aliases' => $entity->aliases,
        ]);

        $this->graph->projectTopics($entity, $node, 'related_to');

        foreach ($entity->outgoingRelationships()->with('targetEntity')->get() as $relationship) {
            if ($relationship->targetEntity) {
                $this->graph->edge($node, $this->project($relationship->targetEntity), 'connected_to', null, null, ['relationship_type' => $relationship->relationship_type], $entity->brand_id);
            }
        }

        return $node;
    }
}
