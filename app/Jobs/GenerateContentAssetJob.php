<?php

namespace App\Jobs;

use App\Models\GeneratedAsset;
use App\Services\ContentGenerationService;
use App\Services\GenerationRunLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateContentAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $generatedAssetId)
    {
        $this->onQueue(config('queue.names.ai', 'ai'));
    }

    public function handle(ContentGenerationService $generation): void
    {
        $generatedAsset = GeneratedAsset::query()->findOrFail($this->generatedAssetId);

        $generation->processGeneratedAsset($generatedAsset);
    }

    public function failed(Throwable $exception): void
    {
        $generatedAsset = GeneratedAsset::query()->find($this->generatedAssetId);

        if (! $generatedAsset) {
            return;
        }

        $generatedAsset->forceFill([
            'status' => 'failed',
            'output_payload' => [
                'error' => $exception->getMessage(),
                'fake' => true,
            ],
        ])->save();

        app(GenerationRunLogger::class)->failed($generatedAsset, 'Content generation run failed: '.$exception->getMessage());
    }
}
