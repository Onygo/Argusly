<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Models\AgenticMarketingObjective;

interface AgenticMarketingOpportunityDetector
{
    /**
     * @return array<int,DetectedOpportunity>
     */
    public function detect(AgenticMarketingObjective $objective): array;
}
