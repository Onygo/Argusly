<?php

namespace App\Events\Connectors\Normalization;

use App\Models\Connectors\NormalizationRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NormalizedCampaignDataUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string, int> $entityCounts
     */
    public function __construct(
        public readonly NormalizationRun $run,
        public readonly array $entityCounts = [],
    ) {
    }
}
