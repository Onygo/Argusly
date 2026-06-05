<?php

namespace App\Jobs\AgenticMarketing\ExecutionPipeline;

use App\Models\AgenticMarketingExecutionAsset;
use App\Services\AgenticMarketing\ExecutionPipeline\ExecutionAuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateOpportunityExecutionAssetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $assetId) {}

    public function handle(ExecutionAuditLogger $auditLogger): void
    {
        $asset = AgenticMarketingExecutionAsset::query()
            ->with('pipeline')
            ->findOrFail($this->assetId);

        $before = $asset->attributesToArray();
        $payload = (array) ($asset->payload ?? []);
        $payload['generation'] = [
            'status' => 'prepared',
            'generator' => self::class,
            'generated_at' => now()->toIso8601String(),
            'ai_ready' => true,
        ];

        $asset->forceFill([
            'status' => 'generated',
            'payload' => $payload,
        ])->save();

        $auditLogger->record(
            $asset->pipeline,
            'asset.generated_by_job',
            before: $before,
            after: $asset->fresh()->attributesToArray(),
            asset: $asset,
        );
    }
}
