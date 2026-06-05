<?php

namespace App\Billing\Providers;

use App\Models\CreditPackPurchase;
use App\Models\PaymentIntent;
use App\Models\Subscription;

interface PaymentProvider
{
    public function name(): string;

    public function createPackPaymentIntent(
        CreditPackPurchase $purchase,
        PaymentIntent $intent
    ): array;

    public function createSubscriptionPaymentIntent(
        Subscription $subscription,
        PaymentIntent $intent
    ): array;

    public function fetchActiveMandateId(string $customerId): ?string;

    public function createRecurringSubscription(Subscription $subscription): array;

    public function fetchPayment(string $providerPaymentId): array;

    public function parseWebhook(string $rawBody): array;
}
