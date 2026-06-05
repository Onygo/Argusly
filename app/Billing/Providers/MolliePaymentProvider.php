<?php

namespace App\Billing\Providers;

use App\Models\CreditPackPurchase;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\ValidationException;
use Mollie\Api\MollieApiClient;
use RuntimeException;

class MolliePaymentProvider implements PaymentProvider
{
    private MollieApiClient $mollie;

    public function __construct()
    {
        $key = (string) config('billing.mollie.key');

        if (trim($key) === '') {
            throw new RuntimeException('MOLLIE_KEY is not set (config billing.mollie.key).');
        }

        $this->mollie = new MollieApiClient();
        $this->mollie->setApiKey($key);
    }

    public function name(): string
    {
        return 'mollie';
    }

    public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array
    {
        $amountValue = number_format(((int) $purchase->price_cents) / 100, 2, '.', '');

        $redirectUrl = $this->buildRedirectUrl(
            (string) config('billing.urls.pack_return'),
            [
                'pi' => (string) $intent->id,
                'purchase_id' => (string) $purchase->id,
            ]
        );

        if (trim($redirectUrl) === '') {
            throw new RuntimeException('Billing URL missing: billing.urls.pack_return.');
        }

        $payload = [
            'amount' => [
                'currency' => (string) $purchase->currency,
                'value' => $amountValue,
            ],
            'description' => 'Credit pack ' . (string) data_get($purchase->meta, 'pack_key', ''),
            'redirectUrl' => $redirectUrl,
            'method' => 'ideal',
            'metadata' => [
                'purpose' => 'credit_pack',
                'purchase_id' => (string) $purchase->id,
                'payment_intent_id' => (string) $intent->id,
                'client_site_id' => (string) $purchase->client_site_id,
            ],
        ];

        $webhookUrl = (string) config('billing.urls.pack_webhook');
        if ($this->shouldAttachWebhookUrl($webhookUrl)) {
            $payload['webhookUrl'] = $webhookUrl;
        } elseif (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('billing.urls.pack_webhook must be a public URL in non-local environments.');
        }

        try {
            $payment = $this->mollie->payments->create($payload);
        } catch (ValidationException $exception) {
            $message = strtolower($exception->getMessage());
            $isNoSuitableMethods = str_contains($message, 'no suitable payment methods found');

            if (! $isNoSuitableMethods) {
                throw $exception;
            }

            // Fallback for profiles without recurring-compatible checkout methods:
            // create a normal one-off signup payment so user can continue and
            // subscription remains pending_mandate until mandate setup succeeds.
            $fallback = $payload;
            $fallback['sequenceType'] = 'oneoff';
            unset($fallback['customerId']);

            $payment = $this->mollie->payments->create($fallback);
        }

        $checkoutUrl = method_exists($payment, 'getCheckoutUrl') ? $payment->getCheckoutUrl() : null;

        if (! $checkoutUrl) {
            throw new RuntimeException('Mollie did not return a checkout URL.');
        }

        return [
            'provider_payment_id' => (string) $payment->id,
            'checkout_url' => (string) $checkoutUrl,
            'status' => (string) $payment->status,
            'provider_customer_id' => (string) ($payment->customerId ?? ''),
            'provider_mandate_id' => (string) ($payment->mandateId ?? ''),
            'provider_subscription_id' => null,
        ];
    }

    public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
    {
        $amountValue = number_format(((int) $intent->amount_cents) / 100, 2, '.', '');
        $customerId = $this->ensureProviderCustomerId($subscription);
        $purpose = (string) data_get($intent->meta, 'purpose', '');

        // Determine if this should be a recurring charge (has valid mandate) or first payment
        $existingMandateId = trim((string) ($subscription->provider_mandate_id ?? ''));
        $isRecurring = false;
        $validatedMandateId = '';

        if ($existingMandateId !== '') {
            // Validate the mandate is still active before using recurring sequence
            $validatedMandateId = $this->fetchActiveMandateId($customerId);
            $isRecurring = $validatedMandateId !== null && $validatedMandateId !== '';

            if (! $isRecurring) {
                Log::warning('billing.mollie.mandate_invalid_for_recurring', [
                    'subscription_id' => (string) $subscription->id,
                    'stored_mandate_id' => $existingMandateId,
                    'customer_id' => $customerId,
                    'purpose' => $purpose,
                ]);
            }
        }

        $redirectUrl = $this->buildRedirectUrl(
            (string) config('billing.urls.pack_return'),
            [
                'pi' => (string) $intent->id,
                'subscription_id' => (string) $subscription->id,
            ]
        );

        if (trim($redirectUrl) === '') {
            throw new RuntimeException('Billing URL missing: billing.urls.pack_return.');
        }

        $payload = [
            'amount' => [
                'currency' => (string) $intent->currency,
                'value' => $amountValue,
            ],
            'description' => 'Subscription ' . (string) ($subscription->plan?->name ?? 'Plan'),
            'redirectUrl' => $redirectUrl,
            'sequenceType' => $isRecurring ? 'recurring' : 'first',
            'customerId' => $customerId,
            'metadata' => [
                'purpose' => $purpose !== '' ? $purpose : 'subscription',
                'subscription_id' => (string) $subscription->id,
                'payment_intent_id' => (string) $intent->id,
                'organization_id' => (string) $subscription->organization_id,
                'line_items' => is_array(data_get($intent->meta, 'line_items')) ? data_get($intent->meta, 'line_items') : [],
                'recurring_amount_cents' => (int) data_get($intent->meta, 'recurring_amount_cents', 0),
                'onboarding_amount_cents' => (int) data_get($intent->meta, 'onboarding_amount_cents', 0),
                'total_due_today_cents' => (int) data_get($intent->meta, 'total_due_today_cents', (int) $intent->amount_cents),
                'onboarding_required' => (bool) data_get($intent->meta, 'onboarding_required', false),
                'onboarding_label' => (string) data_get($intent->meta, 'onboarding_label', ''),
            ],
        ];

        // For recurring payments, explicitly specify the mandate to ensure correct card is charged
        if ($isRecurring && $validatedMandateId !== '') {
            $payload['mandateId'] = $validatedMandateId;
        }

        // Do not force iDEAL for subscription onboarding: sequenceType "first"
        // requires a recurring-capable method, and iDEAL is not recurring.
        // Let Mollie present valid methods for this customer/profile.

        $webhookUrl = (string) config('billing.urls.pack_webhook');
        if ($this->shouldAttachWebhookUrl($webhookUrl)) {
            $payload['webhookUrl'] = $webhookUrl;
        } elseif (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('billing.urls.pack_webhook must be a public URL in non-local environments.');
        }

        Log::info('billing.mollie.creating_payment', [
            'subscription_id' => (string) $subscription->id,
            'payment_intent_id' => (string) $intent->id,
            'sequence_type' => $payload['sequenceType'],
            'is_recurring' => $isRecurring,
            'mandate_id' => $validatedMandateId,
            'amount' => $amountValue,
            'purpose' => $purpose,
        ]);

        try {
            $payment = $this->mollie->payments->create($payload);
        } catch (ValidationException $exception) {
            $message = strtolower($exception->getMessage());
            $isNoSuitableMethods = str_contains($message, 'no suitable payment methods found');

            if (! $isNoSuitableMethods) {
                throw $exception;
            }

            throw new RuntimeException(
                'No recurring-capable payment methods are active in Mollie for this profile. ' .
                'Enable methods like credit card or direct debit in Mollie and retry.'
            );
        }

        $checkoutUrl = method_exists($payment, 'getCheckoutUrl') ? $payment->getCheckoutUrl() : null;

        Log::info('billing.mollie.payment_created', [
            'subscription_id' => (string) $subscription->id,
            'payment_intent_id' => (string) $intent->id,
            'provider_payment_id' => (string) $payment->id,
            'sequence_type' => $payload['sequenceType'],
            'status' => (string) $payment->status,
            'has_checkout_url' => $checkoutUrl !== null && $checkoutUrl !== '',
        ]);

        return [
            'provider_payment_id' => (string) $payment->id,
            'checkout_url' => (string) ($checkoutUrl ?? ''),
            'status' => (string) $payment->status,
            'provider_customer_id' => (string) ($payment->customerId ?? $customerId),
            'provider_mandate_id' => (string) ($payment->mandateId ?? $validatedMandateId),
            'provider_subscription_id' => (string) ($payment->subscriptionId ?? ''),
            'is_recurring' => $isRecurring,
        ];
    }

    public function fetchPayment(string $providerPaymentId): array
    {
        $providerPaymentId = trim($providerPaymentId);

        if ($providerPaymentId === '') {
            throw new RuntimeException('Missing provider_payment_id.');
        }

        $payment = $this->mollie->payments->get($providerPaymentId);

        return [
            'id' => (string) $payment->id,
            'status' => (string) $payment->status,
            'is_paid' => (bool) $payment->isPaid(),
            'is_failed' => (bool) $payment->isFailed(),
            'is_canceled' => (bool) $payment->isCanceled(),
            'is_expired' => method_exists($payment, 'isExpired') ? (bool) $payment->isExpired() : false,
            'is_refunded' => method_exists($payment, 'isRefunded') ? (bool) $payment->isRefunded() : false,
            'metadata' => (array) ($payment->metadata ?? []),
            'amount' => [
                'currency' => (string) ($payment->amount?->currency ?? ''),
                'value' => (string) ($payment->amount?->value ?? ''),
            ],
            'provider_customer_id' => (string) ($payment->customerId ?? ''),
            'provider_mandate_id' => (string) ($payment->mandateId ?? ''),
            'provider_subscription_id' => (string) ($payment->subscriptionId ?? ''),
        ];
    }

    public function parseWebhook(string $rawBody): array
    {
        $rawBody = trim((string) $rawBody);

        $data = [];
        parse_str($rawBody, $data);

        $id = (string) ($data['id'] ?? '');

        if ($id === '') {
            throw new RuntimeException('Invalid Mollie webhook payload: missing id.');
        }

        if (str_starts_with($id, 'sub_')) {
            $eventNonce = str_replace('.', '', sprintf('%.6F', microtime(true)));

            return [
                'provider_event_id' => 'sub:' . $id . ':' . $eventNonce,
                'event_type' => 'subscription.updated',
                'provider_payment_id' => '',
                'provider_subscription_id' => $id,
            ];
        }

        return [
            'provider_event_id' => $id,
            'event_type' => 'payment.updated',
            'provider_payment_id' => $id,
        ];
    }

    public function fetchActiveMandateId(string $customerId): ?string
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return null;
        }

        try {
            $mandates = $this->mollie->customers->get($customerId)->mandates();

            foreach ($mandates as $mandate) {
                if ((string) ($mandate->status ?? '') === 'valid') {
                    return (string) $mandate->id;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Check if the customer has a valid mandate for recurring charges.
     *
     * @return array{can_charge: bool, mandate_id: ?string, mandate_method: ?string, reason: ?string}
     */
    public function canChargeRecurring(string $customerId): array
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return [
                'can_charge' => false,
                'mandate_id' => null,
                'mandate_method' => null,
                'reason' => 'no_customer_id',
            ];
        }

        try {
            $mandates = $this->mollie->customers->get($customerId)->mandates();

            foreach ($mandates as $mandate) {
                $status = (string) ($mandate->status ?? '');
                $method = (string) ($mandate->method ?? '');

                if ($status === 'valid') {
                    return [
                        'can_charge' => true,
                        'mandate_id' => (string) $mandate->id,
                        'mandate_method' => $method,
                        'reason' => null,
                    ];
                }
            }

            return [
                'can_charge' => false,
                'mandate_id' => null,
                'mandate_method' => null,
                'reason' => 'no_valid_mandate',
            ];
        } catch (\Throwable $exception) {
            Log::warning('billing.mollie.mandate_check_failed', [
                'customer_id' => $customerId,
                'error' => $exception->getMessage(),
            ]);

            return [
                'can_charge' => false,
                'mandate_id' => null,
                'mandate_method' => null,
                'reason' => 'mandate_check_error',
            ];
        }
    }

    /**
     * Get detailed mandate information for a customer.
     *
     * @return array<int, array{id: string, status: string, method: string, details: array}>
     */
    public function getMandateDetails(string $customerId): array
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return [];
        }

        try {
            $mandates = $this->mollie->customers->get($customerId)->mandates();
            $result = [];

            foreach ($mandates as $mandate) {
                $result[] = [
                    'id' => (string) ($mandate->id ?? ''),
                    'status' => (string) ($mandate->status ?? ''),
                    'method' => (string) ($mandate->method ?? ''),
                    'details' => [
                        'card_holder' => (string) ($mandate->details?->cardHolder ?? ''),
                        'card_number' => (string) ($mandate->details?->cardNumber ?? ''),
                        'card_label' => (string) ($mandate->details?->cardLabel ?? ''),
                    ],
                ];
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    public function createRecurringSubscription(Subscription $subscription): array
    {
        $customerId = (string) ($subscription->provider_customer_id ?? '');
        if ($customerId === '') {
            throw new RuntimeException('Cannot create recurring subscription without provider_customer_id.');
        }

        $interval = (string) ($subscription->interval ?: 'month');
        $mollieInterval = $interval === 'year' ? '12 months' : '1 month';

        $payload = [
            'amount' => [
                'currency' => (string) ($subscription->currency ?: 'EUR'),
                'value' => number_format(((int) $subscription->price_cents) / 100, 2, '.', ''),
            ],
            'interval' => $mollieInterval,
            'description' => 'PublishLayer subscription ' . (string) ($subscription->plan?->name ?? 'Plan'),
            'webhookUrl' => (string) config('billing.urls.pack_webhook'),
            'metadata' => [
                'purpose' => 'subscription_renewal',
                'subscription_id' => (string) $subscription->id,
                'organization_id' => (string) $subscription->organization_id,
            ],
        ];

        $created = $this->mollie->subscriptions->createForId($customerId, $payload);

        return [
            'provider_subscription_id' => (string) ($created->id ?? ''),
            'status' => (string) ($created->status ?? ''),
        ];
    }

    /**
     * @return array{
     *   status:string,
     *   next_payment_at:string|null,
     *   canceled_at:string|null
     * }
     */
    public function fetchSubscriptionDetails(string $customerId, string $subscriptionId): array
    {
        $customerId = trim($customerId);
        $subscriptionId = trim($subscriptionId);

        if ($customerId === '' || $subscriptionId === '') {
            throw new RuntimeException('Cannot fetch subscription details without customer and subscription id.');
        }

        $subscription = $this->mollie->subscriptions->getForId($customerId, $subscriptionId);

        $nextPaymentDate = (string) ($subscription->nextPaymentDate ?? '');
        $nextPaymentAt = $nextPaymentDate !== '' ? $nextPaymentDate . ' 00:00:00' : null;

        $canceledAtRaw = (string) ($subscription->canceledAt ?? $subscription->endedAt ?? '');
        $canceledAt = $canceledAtRaw !== '' ? $canceledAtRaw : null;

        return [
            'status' => strtolower((string) ($subscription->status ?? '')),
            'next_payment_at' => $nextPaymentAt,
            'canceled_at' => $canceledAt,
        ];
    }

    private function shouldAttachWebhookUrl(string $webhookUrl): bool
    {
        $webhookUrl = trim($webhookUrl);
        if ($webhookUrl === '') {
            return false;
        }

        $host = (string) parse_url($webhookUrl, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }

        $host = strtolower($host);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return false;
        }

        return true;
    }

    private function buildRedirectUrl(string $baseUrl, array $params): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            return $baseUrl;
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts)) {
            return $baseUrl;
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $query[$key] = $value;
            }
        }

        $rebuilt = '';
        if (! empty($parts['scheme'])) {
            $rebuilt .= $parts['scheme'] . '://';
        }
        if (! empty($parts['user'])) {
            $rebuilt .= $parts['user'];
            if (! empty($parts['pass'])) {
                $rebuilt .= ':' . $parts['pass'];
            }
            $rebuilt .= '@';
        }
        $rebuilt .= $parts['host'] ?? '';
        if (! empty($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';

        $queryString = http_build_query($query);
        if ($queryString !== '') {
            $rebuilt .= '?' . $queryString;
        }
        if (! empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    private function ensureProviderCustomerId(Subscription $subscription): string
    {
        $existing = trim((string) ($subscription->provider_customer_id ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $subscription->loadMissing('organization.primaryUser', 'organization.users');
        $organization = $subscription->organization;

        $name = trim((string) (
            $organization?->billing_company_name
            ?: $organization?->name
            ?: 'PublishLayer customer'
        ));

        $email = trim((string) (
            $organization?->primaryUser?->email
            ?: $organization?->users()->orderBy('created_at')->value('email')
            ?: 'billing@publishlayer.com'
        ));

        $customer = $this->mollie->customers->create([
            'name' => $name,
            'email' => $email,
            'metadata' => array_filter([
                'organization_id' => (string) ($organization?->id ?? ''),
                'subscription_id' => (string) $subscription->id,
            ]),
        ]);

        $customerId = trim((string) ($customer->id ?? ''));
        if ($customerId === '') {
            throw new RuntimeException('Mollie did not return a customer id for recurring setup.');
        }

        $subscription->provider_customer_id = $customerId;
        $subscription->save();

        return $customerId;
    }
}
