<?php

namespace App\Services\Graph\Projectors;

use App\Models\Campaign;
use App\Models\GraphNode;
use App\Services\Graph\GraphProjectionService;

class CampaignGraphProjector
{
    public function __construct(private readonly GraphProjectionService $graph) {}

    public function project(Campaign $campaign): GraphNode
    {
        $node = $this->graph->node($campaign, 'campaign', $campaign->name, [
            'slug' => $campaign->slug,
            'status' => $campaign->status,
            'objective' => $campaign->objective,
            'start_date' => $campaign->start_date?->toDateString(),
            'end_date' => $campaign->end_date?->toDateString(),
        ]);

        foreach ($campaign->topics()->get() as $topic) {
            $this->graph->edge($node, $this->graph->topics->project($topic), 'targets', null, null, ['source' => 'campaign_topics'], $campaign->brand_id);
        }

        foreach ($campaign->contentAssets()->get() as $asset) {
            $this->graph->edge($this->graph->content($asset), $node, 'participates_in', null, null, ['source' => 'campaign_assets'], $campaign->brand_id);
        }

        return $node;
    }
}
