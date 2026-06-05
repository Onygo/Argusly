<?php

namespace App\Jobs\CampaignClusterEngine;

use App\Models\AgenticActionRun;
use App\Models\Workspace;
use App\Services\CampaignClusterEngine\CampaignClusterPlanningEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class GenerateCampaignClustersJob implements ShouldQueue
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

    public function handle(CampaignClusterPlanningEngine $engine): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $this->updateAuditRun(AgenticActionRun::STATUS_RUNNING);

        $engine->run($workspace, $this->clientSiteId, $this->options);
        $this->updateAuditRun(AgenticActionRun::STATUS_COMPLETED);
    }

    public function failed(Throwable $exception): void
    {
        $this->updateAuditRun(AgenticActionRun::STATUS_FAILED, $exception);
    }

    private function updateAuditRun(string $status, ?Throwable $exception = null): void
    {
        $runId = trim((string) ($this->options['agentic_action_run_id'] ?? ''));
        if ($runId === '') {
            return;
        }

        $run = AgenticActionRun::query()->find($runId);
        if (! $run) {
            return;
        }

        $message = $exception ? Str::limit($exception->getMessage() ?: 'Campaign cluster planning failed.', 240) : null;
        $run->forceFill(array_filter([
            'status' => $status,
            'job_id' => $this->job?->getJobId(),
            'reason' => $message ?: $run->reason,
            'error_message' => $message,
        ], fn (mixed $value): bool => $value !== null))->save();
    }
}
