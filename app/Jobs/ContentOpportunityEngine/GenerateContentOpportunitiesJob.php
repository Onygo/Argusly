<?php

namespace App\Jobs\ContentOpportunityEngine;

use App\Models\Workspace;
use App\Services\ContentOpportunityEngine\ContentOpportunityEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateContentOpportunitiesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $workspaceId,
        public readonly ?string $clientSiteId = null,
        public readonly array $options = [],
    ) {}

    public function handle(ContentOpportunityEngine $engine): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceId);

        $engine->run($workspace, $this->clientSiteId, $this->options);
    }
}
