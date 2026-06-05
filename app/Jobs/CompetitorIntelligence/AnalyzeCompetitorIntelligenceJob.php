<?php

namespace App\Jobs\CompetitorIntelligence;

use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\CompetitorIntelligence\CompetitorIntelligenceAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeCompetitorIntelligenceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $workspaceId,
        public readonly ?int $siteCompetitorId = null,
        public readonly array $input = [],
    ) {}

    public function handle(CompetitorIntelligenceAnalyzer $analyzer): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $competitor = $this->siteCompetitorId
            ? SiteCompetitor::query()->where('workspace_id', $workspace->id)->findOrFail($this->siteCompetitorId)
            : null;

        $analyzer->analyze($workspace, $competitor, $this->input);
    }
}
