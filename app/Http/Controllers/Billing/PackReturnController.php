<?php

namespace App\Http\Controllers\Billing;

use App\Billing\Providers\PaymentProviderRegistry;
use App\Enums\Billing\SubscriptionPlanChangeStatus;
use App\Models\CreditPackPurchase;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Services\CreditPackPurchaseService;
use App\Services\CreditWalletService;
use App\Services\InvoiceCreatorService;
use App\Services\Onboarding\OnboardingStateService;
use App\Services\SubscriptionLifecycleService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class PackReturnController extends Controller
{
    public function handle(
        Request $request,
        PaymentProviderRegistry $providers,
        CreditPackPurchaseService $purchases,
        CreditWalletService $wallets,
        InvoiceCreatorService $invoiceCreator,
        SubscriptionLifecycleService $subscriptionLifecycle,
        SubscriptionService $subscriptions,
        OnboardingStateService $onboardingStates
    )
    {
        $intent = $this->resolveIntentFromRequest($request);
        $intent = $this->syncCreditPackIntentFromProvider(
            $intent,
            $providers,
            $purchases,
            $wallets,
            $invoiceCreator
        );
        $intent = $this->syncSubscriptionIntentFromProvider(
            $intent,
            $providers,
            $subscriptionLifecycle,
            $subscriptions,
            $invoiceCreator,
            $onboardingStates
        );
        $state = $this->resolveLocalState($intent);

        Log::info('billing.return.checked', [
            'intent_id' => (string) ($intent?->id ?? ''),
            'billable_type' => (string) ($intent?->billable_type ?? ''),
            'billable_id' => (string) ($intent?->billable_id ?? ''),
            'provider' => (string) ($intent?->provider ?? ''),
            'provider_payment_id' => (string) ($intent?->provider_payment_id ?? ''),
            'payment_intent_status' => (string) ($intent?->status ?? ''),
            'resolved_state' => $state['state'],
        ]);

        if ($state['is_complete'] && $request->user()) {
            if ($intent?->billable_type === Subscription::class) {
                $subscription = Subscription::query()->find($intent->billable_id);
                $activeSiteCount = (int) $request->user()->organization?->clientSites()->where('is_active', true)->count();

                if ($subscription && in_array((string) $subscription->status, ['active', 'trialing'], true) && $activeSiteCount < 1) {
                    return redirect()
                        ->route('app.sites')
                        ->with('status', 'Subscription activated. Connect your first site to start publishing.');
                }
            }

            return redirect()
                ->route('app.billing.index')
                ->with('status', (string) $state['message']);
        }

        if ($state['is_failed'] && $request->user()) {
            return redirect()
                ->route('app.billing.index')
                ->withErrors(['billing' => (string) $state['message']]);
        }

        if ($state['is_complete']) {
            return view('billing.success', [
                'message' => $state['message'],
                'summaryLines' => $state['summary_lines'] ?? [],
            ]);
        }

        return view('billing.processing', [
            'message' => $state['message'],
            'poll_url' => $request->fullUrl(),
            'state' => $state['state'],
            'intent_id' => (string) ($intent?->id ?? ''),
        ]);
    }

    private function resolveLocalState(?PaymentIntent $intent): array
    {
        if (! $intent) {
            return [
                'state' => 'unknown',
                'is_complete' => false,
                'is_failed' => false,
                'message' => 'Payment is being processed. This page will refresh automatically.',
            ];
        }

        $intentStatus = (string) ($intent->status ?? '');
        if (in_array($intentStatus, ['failed', 'canceled', 'expired'], true)) {
            return [
                'state' => 'failed',
                'is_complete' => false,
                'is_failed' => true,
                'message' => 'Payment was not completed. Please retry checkout from Billing.',
            ];
        }

        if ($intent->billable_type === CreditPackPurchase::class) {
            $purchase = CreditPackPurchase::query()->find($intent->billable_id);
            if ($purchase && $purchase->status === 'paid') {
                return [
                    'state' => 'completed',
                    'is_complete' => true,
                    'is_failed' => false,
                    'message' => 'Payment confirmed and credits are available.',
                    'summary_lines' => [],
                ];
            }

            return [
                'state' => 'processing',
                'is_complete' => false,
                'is_failed' => false,
                'message' => 'Payment received. Waiting for webhook confirmation before applying credits.',
            ];
        }

        if ($intent->billable_type === Subscription::class) {
            $subscription = Subscription::query()->with('plan')->find($intent->billable_id);
            if ($subscription && in_array((string) $subscription->status, ['active', 'trialing'], true)) {
                $onboardingLabel = collect(is_array(data_get($intent->meta, 'line_items')) ? data_get($intent->meta, 'line_items') : [])
                    ->first(static fn (mixed $item): bool => (string) data_get($item, 'code') === 'onboarding');
                $message = sprintf('Subscription activated on %s.', (string) ($subscription->plan?->name ?? 'your selected plan'));

                if (is_array($onboardingLabel) && (int) data_get($onboardingLabel, 'amount_cents', 0) > 0) {
                    $message .= ' ' . sprintf('%s payment confirmed.', (string) data_get($onboardingLabel, 'label', 'Onboarding'));
                }

                return [
                    'state' => 'completed',
                    'is_complete' => true,
                    'is_failed' => false,
                    'message' => $message,
                    'summary_lines' => collect(is_array(data_get($intent->meta, 'line_items')) ? data_get($intent->meta, 'line_items') : [])
                        ->map(static fn (array $item): array => [
                            'label' => (string) data_get($item, 'label', 'Line item'),
                            'amount_cents' => (int) data_get($item, 'amount_cents', 0),
                            'type' => (string) data_get($item, 'type', 'one_time'),
                        ])->values()->all(),
                ];
            }

            return [
                'state' => 'processing',
                'is_complete' => false,
                'is_failed' => false,
                'message' => 'Payment received. Subscription activation is in progress and will update automatically.',
            ];
        }

        if ($intent->billable_type === SubscriptionPlanChange::class) {
            $change = SubscriptionPlanChange::query()->find($intent->billable_id);
            if ($change && $change->status === SubscriptionPlanChangeStatus::APPLIED) {
                return [
                    'state' => 'completed',
                    'is_complete' => true,
                    'is_failed' => false,
                    'message' => 'Plan change has been applied.',
                ];
            }

            if ($change && in_array($change->status, [SubscriptionPlanChangeStatus::FAILED, SubscriptionPlanChangeStatus::BLOCKED], true)) {
                return [
                    'state' => 'failed',
                    'is_complete' => false,
                    'is_failed' => true,
                    'message' => 'Plan change failed. Your current plan remains active.',
                ];
            }

            return [
                'state' => 'processing',
                'is_complete' => false,
                'is_failed' => false,
                'message' => 'Payment received. Plan change is being finalized.',
            ];
        }

        return [
            'state' => 'processing',
            'is_complete' => false,
            'is_failed' => false,
            'message' => 'Payment is being processed. This page will refresh automatically.',
        ];
    }

    private function syncCreditPackIntentFromProvider(
        ?PaymentIntent $intent,
        PaymentProviderRegistry $providers,
        CreditPackPurchaseService $purchases,
        CreditWalletService $wallets,
        InvoiceCreatorService $invoiceCreator
    ): ?PaymentIntent {
        if (! $intent || $intent->billable_type !== CreditPackPurchase::class) {
            return $intent;
        }

        if (in_array((string) $intent->status, ['paid', 'failed', 'canceled', 'expired'], true)) {
            return $intent;
        }

        $providerPaymentId = trim((string) $intent->provider_payment_id);
        if ($providerPaymentId === '') {
            return $intent;
        }

        try {
            $provider = $providers->get((string) ($intent->provider ?: config('billing.default_provider', 'mollie')));
            $status = $provider->fetchPayment($providerPaymentId);
        } catch (\Throwable $exception) {
            Log::warning('billing.return.provider_fetch_failed', [
                'intent_id' => (string) $intent->id,
                'provider_payment_id' => $providerPaymentId,
                'error' => $exception->getMessage(),
            ]);

            return $intent;
        }

        $dirty = false;
        $providerStatus = (string) ($status['status'] ?? '');
        if ($providerStatus !== '' && $providerStatus !== (string) $intent->status) {
            $intent->status = $providerStatus;
            $dirty = true;
        }

        if ($providerStatus !== '' && $providerStatus !== (string) $intent->last_provider_status) {
            $intent->last_provider_status = $providerStatus;
            $dirty = true;
        }

        if (! empty($status['is_paid']) && ! $intent->paid_at) {
            $intent->paid_at = now();
            $dirty = true;
        }
        if (! empty($status['is_failed']) && ! $intent->failed_at) {
            $intent->failed_at = now();
            $dirty = true;
        }
        if ((! empty($status['is_canceled']) || ! empty($status['is_expired'])) && ! $intent->canceled_at) {
            $intent->canceled_at = now();
            $dirty = true;
        }

        if ($dirty) {
            $intent->save();
        }

        if (! empty($status['is_paid'])) {
            $purchase = CreditPackPurchase::query()->find($intent->billable_id);

            if ($purchase && $purchase->status === 'pending') {
                $purchases->markPaid($purchase, $wallets, $providerPaymentId);
                $invoiceCreator->ensureInvoiceForPaidIntent($intent->fresh('billable') ?? $intent, $status);

                Log::info('billing.return.pack_paid_fallback', [
                    'intent_id' => (string) $intent->id,
                    'purchase_id' => (string) $purchase->id,
                    'provider_payment_id' => $providerPaymentId,
                ]);
            }
        }

        return $intent->fresh();
    }

    private function syncSubscriptionIntentFromProvider(
        ?PaymentIntent $intent,
        PaymentProviderRegistry $providers,
        SubscriptionLifecycleService $subscriptionLifecycle,
        SubscriptionService $subscriptions,
        InvoiceCreatorService $invoiceCreator,
        OnboardingStateService $onboardingStates
    ): ?PaymentIntent {
        if (! $intent || $intent->billable_type !== Subscription::class) {
            return $intent;
        }

        // Skip if already in a terminal state
        if (in_array((string) $intent->status, ['paid', 'failed', 'canceled', 'expired'], true)) {
            // Even if intent is paid, check if subscription needs activation (webhook may not have arrived)
            if ((string) $intent->status === 'paid') {
                $subscription = Subscription::query()->find($intent->billable_id);
                if ($subscription && $subscription->status === 'pending_mandate') {
                    $this->activateSubscriptionFromPaidIntent($intent, $subscription, $subscriptionLifecycle, $subscriptions, $onboardingStates);
                }
            }
            return $intent;
        }

        $providerPaymentId = trim((string) $intent->provider_payment_id);
        if ($providerPaymentId === '') {
            return $intent;
        }

        try {
            $provider = $providers->get((string) ($intent->provider ?: config('billing.default_provider', 'mollie')));
            $status = $provider->fetchPayment($providerPaymentId);
        } catch (\Throwable $exception) {
            Log::warning('billing.return.subscription_provider_fetch_failed', [
                'intent_id' => (string) $intent->id,
                'provider_payment_id' => $providerPaymentId,
                'error' => $exception->getMessage(),
            ]);

            // Log warning for local development where webhooks won't arrive
            $this->warnIfLocalEnvironmentWithoutWebhooks();

            return $intent;
        }

        $dirty = false;
        $providerStatus = (string) ($status['status'] ?? '');
        if ($providerStatus !== '' && $providerStatus !== (string) $intent->status) {
            $intent->status = $providerStatus;
            $dirty = true;
        }

        if ($providerStatus !== '' && $providerStatus !== (string) $intent->last_provider_status) {
            $intent->last_provider_status = $providerStatus;
            $dirty = true;
        }

        if (! empty($status['is_paid']) && ! $intent->paid_at) {
            $intent->paid_at = now();
            $dirty = true;
        }
        if (! empty($status['is_failed']) && ! $intent->failed_at) {
            $intent->failed_at = now();
            $dirty = true;
        }
        if ((! empty($status['is_canceled']) || ! empty($status['is_expired'])) && ! $intent->canceled_at) {
            $intent->canceled_at = now();
            $dirty = true;
        }

        if ($dirty) {
            $intent->save();
        }

        // Handle paid subscription - activate if still pending
        if (! empty($status['is_paid'])) {
            $subscription = Subscription::query()->find($intent->billable_id);

            if ($subscription && in_array((string) $subscription->status, ['pending_mandate'], true)) {
                $purpose = (string) data_get($intent->meta, 'purpose', 'subscription_renewal');
                $resolvedPaymentId = trim((string) ($status['id'] ?? $intent->provider_payment_id ?? ''));

                Log::info('billing.return.subscription_paid_fallback', [
                    'intent_id' => (string) $intent->id,
                    'subscription_id' => (string) $subscription->id,
                    'organization_id' => (string) $subscription->organization_id,
                    'provider_payment_id' => $resolvedPaymentId,
                    'purpose' => $purpose,
                    'previous_status' => (string) $subscription->status,
                ]);

                // Process initial subscription payment
                $subscriptionLifecycle->onSubscriptionPaymentPaid(
                    subscription: $subscription,
                    providerStatus: $status,
                    providerPaymentId: $resolvedPaymentId
                );

                // Sync organization's active subscription
                if ($subscription->organization) {
                    $subscriptions->syncOrganizationActiveSubscription($subscription->organization);
                }

                $subscription->refresh();
                if (in_array((string) $subscription->status, ['active', 'trialing'], true)) {
                    $onboardingStates->markSubscribedForOrganization(
                        organizationId: (int) $subscription->organization_id,
                        workspaceId: $subscription->workspace_id ? (string) $subscription->workspace_id : null
                    );
                }

                // Create invoice for paid subscription
                $invoiceCreator->ensureInvoiceForPaidIntent($intent->fresh('billable') ?? $intent, $status);
            }
        }

        return $intent->fresh();
    }

    private function activateSubscriptionFromPaidIntent(
        PaymentIntent $intent,
        Subscription $subscription,
        SubscriptionLifecycleService $subscriptionLifecycle,
        SubscriptionService $subscriptions,
        OnboardingStateService $onboardingStates
    ): void {
        $providerPaymentId = trim((string) $intent->provider_payment_id);

        Log::info('billing.return.subscription_reactivation_attempt', [
            'intent_id' => (string) $intent->id,
            'subscription_id' => (string) $subscription->id,
            'subscription_status' => (string) $subscription->status,
            'provider_payment_id' => $providerPaymentId,
        ]);

        // Try to activate with stored mandate info
        $subscriptionLifecycle->activateRecurringIfMandateReady(
            subscription: $subscription,
            grantCredits: true,
            providerPaymentId: $providerPaymentId
        );
        $subscriptionLifecycle->markInitialCheckoutComponentsPaid($subscription->fresh() ?? $subscription, $providerPaymentId);

        if ($subscription->organization) {
            $subscriptions->syncOrganizationActiveSubscription($subscription->organization);
        }

        $subscription->refresh();
        if (in_array((string) $subscription->status, ['active', 'trialing'], true)) {
            $onboardingStates->markSubscribedForOrganization(
                organizationId: (int) $subscription->organization_id,
                workspaceId: $subscription->workspace_id ? (string) $subscription->workspace_id : null
            );
        }
    }

    private function warnIfLocalEnvironmentWithoutWebhooks(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $webhookUrl = (string) config('billing.urls.pack_webhook');
        $host = strtolower((string) parse_url($webhookUrl, PHP_URL_HOST));

        $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');

        if ($isLocalHost) {
            Log::warning('billing.return.local_webhook_warning', [
                'message' => 'Mollie webhooks cannot reach local URLs. Subscription activation relies on return URL sync. Consider using ngrok or similar for local testing.',
                'webhook_url' => $webhookUrl,
            ]);
        }
    }

    private function resolveIntentFromRequest(Request $request): ?PaymentIntent
    {
        $paymentId = trim((string) $request->query('id'));
        if ($paymentId !== '') {
            return PaymentIntent::query()
                ->where('provider_payment_id', $paymentId)
                ->first();
        }

        $intentId = trim((string) $request->query('pi'));
        if ($intentId !== '') {
            return PaymentIntent::query()->find($intentId);
        }

        $purchaseId = trim((string) $request->query('purchase_id'));
        if ($purchaseId !== '') {
            return PaymentIntent::query()
                ->where('billable_type', CreditPackPurchase::class)
                ->where('billable_id', $purchaseId)
                ->latest('created_at')
                ->first();
        }

        return null;
    }
}
