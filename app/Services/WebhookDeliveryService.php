<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WebhookDeliveryService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, WebhookDelivery>
     */
    public function enqueue(string $event, array $payload, ?Account $account = null, ?Brand $brand = null): Collection
    {
        $endpoints = WebhookEndpoint::query()
            ->where('status', 'active')
            ->whereJsonContains('events', $event)
            ->where(fn ($query) => $query
                ->whereNull('account_id')
                ->orWhere('account_id', $account?->id))
            ->where(fn ($query) => $query
                ->whereNull('brand_id')
                ->orWhere('brand_id', $brand?->id))
            ->get();

        return $endpoints->map(fn (WebhookEndpoint $endpoint) => WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'account_id' => $account?->id ?? $endpoint->account_id,
            'brand_id' => $brand?->id ?? $endpoint->brand_id,
            'event' => $event,
            'status' => 'pending',
            'idempotency_key' => "{$event}:".Str::uuid(),
            'payload' => $payload,
            'available_at' => now(),
        ]));
    }
}
