<?php

namespace App\Services;

use App\Models\GeneratedAsset;

class GenerationRunLogger
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function queued(GeneratedAsset $asset): void
    {
        $this->log($asset, 'content.generation.queued', 'Content generation run was queued.');
    }

    public function processing(GeneratedAsset $asset): void
    {
        $this->log($asset, 'content.generation.processing', 'Content generation run started processing.');
    }

    public function completed(GeneratedAsset $asset): void
    {
        $this->log($asset, 'content.generation.completed', 'Content generation run completed with static output.');
    }

    public function failed(GeneratedAsset $asset, ?string $message = null): void
    {
        $this->log($asset, 'content.generation.failed', $message ?: 'Content generation run failed.');
    }

    private function log(GeneratedAsset $asset, string $event, string $description): void
    {
        $this->activity->log(
            event: $event,
            description: $description,
            account: $asset->account,
            brand: $asset->brand,
            subject: $asset,
            properties: [
                'generated_asset_id' => $asset->id,
                'content_asset_id' => $asset->content_asset_id,
                'type' => $asset->type,
                'status' => $asset->status,
                'provider' => $asset->provider,
                'model' => $asset->model,
            ],
        );
    }
}
