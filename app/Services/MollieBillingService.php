<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MollieBillingService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly DomainEventService $events,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createCheckout(Account $account, Plan $plan, ?User $user = null): array
    {
        $subscription = $this->subscriptions->activatePlan($account, $plan, [
            'payment_provider' => 'mollie',
            'checkout_status' => 'pending',
            'requested_by_user_id' => $user?->id,
        ]);

        $payload = [
            'amount' => [
                'currency' => $plan->currency,
                'value' => number_format($plan->amount / 100, 2, '.', ''),
            ],
            'description' => $plan->name.' subscription for '.$account->name,
            'redirectUrl' => route('admin.billing'),
            'webhookUrl' => route('billing.mollie.webhook'),
            'metadata' => [
                'account_id' => $account->id,
                'subscription_id' => $subscription->id,
                'plan_key' => $plan->key,
            ],
        ];

        $response = $this->sendPaymentRequest($payload);
        $providerPaymentId = $response['id'] ?? 'pending_'.Str::uuid()->toString();
        $checkoutUrl = data_get($response, '_links.checkout.href') ?? route('admin.billing');

        $subscription->forceFill([
            'provider' => 'mollie',
            'provider_subscription_id' => $providerPaymentId,
            'metadata' => [
                ...($subscription->metadata ?? []),
                'payment_provider' => 'mollie',
                'checkout_url' => $checkoutUrl,
                'checkout_payload' => $payload,
                'mollie_response' => $response,
            ],
        ])->save();

        $this->events->record('MollieCheckoutCreated', $account, null, $subscription, $user, [
            'plan_key' => $plan->key,
            'provider_payment_id' => $providerPaymentId,
        ], dispatch: false);

        return [
            'subscription' => $subscription->refresh(),
            'checkout_url' => $checkoutUrl,
            'provider_payment_id' => $providerPaymentId,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sendPaymentRequest(array $payload): array
    {
        $apiKey = (string) config('services.mollie.key');

        if ($apiKey === '') {
            return [
                'id' => 'test_'.Str::uuid()->toString(),
                'status' => 'open',
                '_links' => [
                    'checkout' => ['href' => 'https://www.mollie.com/checkout/test-mode'],
                ],
            ];
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->post('https://api.mollie.com/v2/payments', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Mollie payment creation failed: '.$response->body());
        }

        return $response->json();
    }
}
