<?php

namespace App\Services\Graph\Projectors;

use App\Models\GraphNode;
use App\Models\Topic;
use App\Services\Graph\GraphProjectionService;

class TopicGraphProjector
{
    public function __construct(private readonly GraphProjectionService $graph) {}

    public function project(Topic $topic): GraphNode
    {
        $node = $this->graph->node($topic, 'topic', $topic->name, [
            'slug' => $topic->slug,
            'status' => $topic->status,
            'description' => $topic->description,
        ]);

        foreach ($topic->childRelationships()->with('childTopic')->get() as $relationship) {
            if ($relationship->childTopic) {
                $this->graph->edge($node, $this->project($relationship->childTopic), 'related_to', null, null, ['relationship_type' => $relationship->relationship_type], $topic->brand_id);
            }
        }

        foreach ($topic->brands()->get() as $brand) {
            $this->graph->edge($this->graph->brand($brand), $node, 'supports', null, null, ['source' => 'brand_topics'], $brand->id);
        }

        return $node;
    }
}
