<?php

namespace App\Jobs;

use App\Models\PublishingAction;
use App\Services\PublishingService;
use App\Services\Signals\SignalManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishContentAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $publishingActionId)
    {
        $this->onQueue(config('queue.names.publishing', 'publishing'));
    }

    public function handle(PublishingService $publishing): void
    {
        $publishingAction = PublishingAction::query()->findOrFail($this->publishingActionId);

        $publishing->process($publishingAction);
    }

    public function failed(Throwable $exception): void
    {
        $publishingAction = PublishingAction::query()->find($this->publishingActionId);

        if (! $publishingAction) {
            return;
        }

        $publishingAction->forceFill([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'response_payload' => [
                'fake' => true,
                'error' => $exception->getMessage(),
            ],
        ])->save();

        app(SignalManager::class)->produce($publishingAction);
    }
}
