<?php

namespace App\Jobs;

use App\Models\ContentAsset;
use App\Services\ContentLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateContentLifecycleScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $contentAssetId) {}

    public function handle(ContentLifecycleService $lifecycle): void
    {
        $contentAsset = ContentAsset::query()->findOrFail($this->contentAssetId);

        $lifecycle->calculateForContentAsset($contentAsset);
    }
}
