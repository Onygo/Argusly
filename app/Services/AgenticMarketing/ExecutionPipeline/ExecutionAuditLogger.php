<?php

namespace App\Services\AgenticMarketing\ExecutionPipeline;

use App\Models\AgenticMarketingExecutionAsset;
use App\Models\AgenticMarketingExecutionAuditLog;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\User;

class ExecutionAuditLogger
{
    public function record(
        AgenticMarketingExecutionPipeline $pipeline,
        string $event,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?User $actor = null,
        ?AgenticMarketingExecutionAsset $asset = null,
    ): AgenticMarketingExecutionAuditLog {
        return AgenticMarketingExecutionAuditLog::query()->create([
            'pipeline_id' => (string) $pipeline->id,
            'asset_id' => $asset?->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
        ]);
    }
}
