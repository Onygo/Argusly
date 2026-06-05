<?php

namespace App\Services;

use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\CreditPackPurchase;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PackCheckoutService
{
    public function createCheckout(CreditPackPurchase $purchase): PaymentIntent
    {
        return DB::transaction(function () use ($purchase) {
            $intent = PaymentIntent::create([
                'id' => (string) Str::uuid(),
                'billable_type' => CreditPackPurchase::class,
                'billable_id' => $purchase->id,
                'provider' => config('billing.default_provider'),
                'status' => 'pending',
                'amount_cents' => $purchase->price_cents,
                'currency' => $purchase->currency,
                'idempotency_key' => 'pack:' . $purchase->id,
            ]);

            $provider = app(PaymentProviderRegistry::class)->get($intent->provider);

            $result = $provider->createPackPaymentIntent($purchase, $intent);

            $intent->provider_payment_id = $result['provider_payment_id'];
            $intent->checkout_url = $result['checkout_url'];
            $intent->status = $result['status'];
            $intent->save();

            $purchase->provider = $intent->provider;
            if (! empty($result['provider_customer_id'])) {
                $purchase->provider_customer_id = (string) $result['provider_customer_id'];
            }
            if (! empty($result['provider_payment_id'])) {
                $purchase->provider_payment_id = (string) $result['provider_payment_id'];
            }
            $purchase->save();

            Log::info('billing.pack.checkout.created', [
                'purchase_id' => (string) $purchase->id,
                'client_site_id' => (string) $purchase->client_site_id,
                'payment_intent_id' => (string) $intent->id,
                'provider' => (string) $intent->provider,
                'provider_payment_id' => (string) ($intent->provider_payment_id ?? ''),
                'amount_cents' => (int) $intent->amount_cents,
                'currency' => (string) $intent->currency,
            ]);

            return $intent;
        });
    }
}
