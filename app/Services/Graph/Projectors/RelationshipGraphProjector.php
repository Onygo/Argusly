<?php

namespace App\Services\Graph\Projectors;

use App\Models\GraphNode;
use App\Models\Relationship;
use App\Services\Graph\GraphProjectionService;

class RelationshipGraphProjector
{
    public function __construct(private readonly GraphProjectionService $graph) {}

    public function project(Relationship $relationship): ?GraphNode
    {
        $from = $relationship->from;
        $to = $relationship->to;

        if (! $from || ! $to) {
            return null;
        }

        $fromNode = $this->graph->project($from);
        $toNode = $this->graph->project($to);

        if (! $fromNode || ! $toNode) {
            return null;
        }

        $this->graph->edge($fromNode, $toNode, 'connected_to', $relationship->strength, null, [
            'source' => 'relationships',
            'relationship_type' => $relationship->relationship_type,
        ]);

        return $fromNode;
    }
}
