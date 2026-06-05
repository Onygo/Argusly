<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchWebhookDeliveryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue(config('queue.names.webhooks', 'webhooks'));
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if (! $delivery || ! in_array($delivery->status, ['pending', 'failed'], true)) {
            return;
        }

        $delivery->update([
            'status' => 'processing',
            'attempts' => $delivery->attempts + 1,
        ]);

        // Network delivery is intentionally left to a dedicated outbound adapter.
        // The foundation records the retryable state without coupling tests to HTTP.
        $delivery->update([
            'status' => 'pending',
            'available_at' => now()->addMinutes(min(60, max(1, $delivery->attempts * 5))),
            'next_retry_at' => now()->addMinutes(min(60, max(1, $delivery->attempts * 5))),
        ]);
    }
}
