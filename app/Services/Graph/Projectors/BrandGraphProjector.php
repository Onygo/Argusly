<?php

namespace App\Services\Graph\Projectors;

use App\Models\Brand;
use App\Models\GraphNode;
use App\Services\Graph\GraphProjectionService;

class BrandGraphProjector
{
    public function __construct(private readonly GraphProjectionService $graph) {}

    public function project(Brand $brand): GraphNode
    {
        $node = $this->graph->brand($brand);

        foreach ($brand->topics()->get() as $topic) {
            $this->graph->edge($node, $this->graph->topics->project($topic), 'supports', null, null, ['source' => 'brand_topics'], $brand->id);
        }

        return $node;
    }
}
