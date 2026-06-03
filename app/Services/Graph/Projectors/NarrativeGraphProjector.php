<?php

namespace App\Services\Graph\Projectors;

use App\Models\GraphNode;
use App\Models\Narrative;
use App\Services\Graph\GraphProjectionService;

class NarrativeGraphProjector
{
    public function __construct(private readonly GraphProjectionService $graph) {}

    public function project(Narrative $narrative): GraphNode
    {
        $node = $this->graph->node($narrative, 'narrative', $narrative->title, [
            'narrative_type' => $narrative->narrative_type,
            'status' => $narrative->status,
            'importance' => $narrative->importance,
        ]);

        foreach ($narrative->topics()->get() as $topic) {
            $this->graph->edge($node, $this->graph->topics->project($topic), 'influences', null, null, ['source' => 'narrative_topics'], $narrative->brand_id);
        }

        foreach ($narrative->entities()->get() as $entity) {
            $this->graph->edge($node, $this->graph->entities->project($entity), 'mentions', null, null, ['source' => 'narrative_entities'], $narrative->brand_id);
        }

        foreach ($narrative->mentions()->get() as $mention) {
            $this->graph->edge($this->graph->mentions->project($mention), $node, 'detected_in', null, null, ['source' => 'narrative_mentions'], $narrative->brand_id);
        }

        foreach ($narrative->competitors()->get() as $competitor) {
            $this->graph->edge($node, $this->graph->competitors($competitor), 'connected_to', null, null, ['source' => 'narrative_competitors'], $narrative->brand_id);
        }

        return $node;
    }
}
