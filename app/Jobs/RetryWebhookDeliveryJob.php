<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RetryWebhookDeliveryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if (! $delivery) {
            return;
        }

        $delivery->update([
            'status' => 'pending',
            'available_at' => now(),
            'failed_at' => null,
            'error_message' => null,
        ]);
    }
}
