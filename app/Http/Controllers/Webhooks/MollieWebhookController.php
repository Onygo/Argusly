<?php

namespace App\Http\Controllers\Webhooks;

use App\Billing\Providers\PaymentProviderRegistry;
use App\Enums\Billing\SubscriptionPlanChangeStatus;
use App\Models\CreditPackPurchase;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Models\WebhookEvent;
use App\Models\CreditLedgerEntry;
use App\Services\CreditPackPurchaseService;
use App\Services\CreditWalletService;
use App\Services\InvoiceCreatorService;
use App\Services\InvoiceService;
use App\Services\Onboarding\OnboardingStateService;
use App\Services\PlanChangeService;
use App\Services\SubscriptionService;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MollieWebhookController extends Controller
{
    public function handle(
        Request $request,
        PaymentProviderRegistry $providers,
        CreditPackPurchaseService $purchases,
        CreditWalletService $wallets,
        InvoiceCreatorService $invoiceCreator,
        InvoiceService $invoices,
        SubscriptionService $subscriptions,
        SubscriptionLifecycleService $subscriptionLifecycle,
        OnboardingStateService $onboardingStates,
        PlanChangeService $planChanges
    ) {
        $rawBody = (string) $request->getContent();
        if (trim($rawBody) === '' && $request->input('id')) {
            $rawBody = 'id=' . urlencode((string) $request->input('id'));
        }

        $provider = $providers->get('mollie');

        try {
            $parsed = $provider->parseWebhook($rawBody);
        } catch (\Throwable) {
            return response()->json(['ok' => true, 'skipped' => 'invalid_payload'], 200);
        }

        $providerPaymentId = (string) ($parsed['provider_payment_id'] ?? '');
        $providerSubscriptionId = (string) ($parsed['provider_subscription_id'] ?? '');
        if ($providerPaymentId === '' && $providerSubscriptionId === '') {
            return response()->json(['ok' => true, 'skipped' => 'missing_id'], 200);
        }

        Log::info('billing.webhook.mollie.received', [
            'provider_payment_id' => $providerPaymentId,
            'provider_subscription_id' => $providerSubscriptionId,
            'provider_event_id' => (string) ($parsed['provider_event_id'] ?? ''),
            'event_type' => (string) ($parsed['event_type'] ?? 'payment.updated'),
            'source_ip' => (string) $request->ip(),
        ]);

        $event = DB::transaction(function () use ($parsed, $request, $rawBody) {
            $existing = WebhookEvent::query()
                ->where('provider', 'mollie')
                ->where('provider_event_id', (string) $parsed['provider_event_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return WebhookEvent::query()->create([
                'id' => (string) Str::uuid(),
                'provider' => 'mollie',
                'provider_event_id' => (string) $parsed['provider_event_id'],
                'event_type' => (string) ($parsed['event_type'] ?? 'payment.updated'),
                'payload' => ['raw' => $rawBody, 'parsed' => $parsed],
                'headers' => $request->headers->all(),
                'source_ip' => (string) $request->ip(),
                'received_at' => now(),
            ]);
        });

        if ($providerPaymentId === '' && $providerSubscriptionId !== '') {
            $subscription = Subscription::query()
                ->where('provider_subscription_id', $providerSubscriptionId)
                ->latest('updated_at')
                ->first();

            if (! $subscription) {
                $event->handled_at = now();
                $event->handler_result = ['skipped' => 'subscription_not_found'];
                $event->save();

                return response()->json(['ok' => true, 'skipped' => 'subscription_not_found'], 200);
            }

            $subscriptionLifecycle->refreshProviderState($subscription);
            if ($subscription->organization) {
                $subscriptions->syncOrganizationActiveSubscription($subscription->organization);
            }

            $event->handled_at = now();
            $event->handler_result = [
                'subscription_id' => (string) $subscription->id,
                'provider_subscription_id' => $providerSubscriptionId,
                'status' => (string) ($subscription->fresh()?->status ?? $subscription->status),
            ];
            $event->save();

            return response()->json(['ok' => true], 200);
        }

        $intent = PaymentIntent::query()
            ->where('provider', 'mollie')
            ->where('provider_payment_id', $providerPaymentId)
            ->latest('created_at')
            ->first();
        $status = null;

        if (! $intent) {
            try {
                $status = $provider->fetchPayment($providerPaymentId);
                $intent = $invoiceCreator->resolveOrCreateIntentFromMolliePayment($providerPaymentId, $status);
            } catch (\Throwable) {
                $intent = null;
            }

            if (! $intent) {
                $event->handled_at = now();
                $event->handler_result = ['skipped' => 'intent_not_found'];
                $event->save();

                return response()->json(['ok' => true, 'skipped' => 'intent_not_found'], 200);
            }
        }

        try {
            $status = $status ?: $provider->fetchPayment((string) $intent->provider_payment_id);

            $intent->status = (string) ($status['status'] ?? $intent->status);
            $intent->last_provider_status = (string) ($status['status'] ?? $intent->last_provider_status);

            if (! empty($status['is_paid'])) {
                $intent->paid_at = $intent->paid_at ?: now();
            }
            if (! empty($status['is_failed'])) {
                $intent->failed_at = now();
            }
            if (! empty($status['is_canceled']) || ! empty($status['is_expired'])) {
                $intent->canceled_at = now();
            }

            $intent->save();

            if (! empty($status['is_paid'])) {
                if ($intent->billable_type === CreditPackPurchase::class) {
                    $purchase = CreditPackPurchase::query()->find($intent->billable_id);
                    if ($purchase) {
                        $purchases->markPaid($purchase, $wallets, (string) $intent->provider_payment_id);
                        $ledgerEntry = $purchase->credit_ledger_entry_id
                            ? CreditLedgerEntry::query()->find($purchase->credit_ledger_entry_id)
                            : null;

                        Log::info('billing.webhook.mollie.pack_paid', [
                            'provider_payment_id' => $providerPaymentId,
                            'payment_intent_id' => (string) $intent->id,
                            'purchase_id' => (string) $purchase->id,
                            'client_site_id' => (string) $purchase->client_site_id,
                            'credits_amount' => (int) $purchase->credits_amount,
                            'workspace_credit_transaction_id' => (string) ($purchase->workspace_credit_transaction_id ?? ''),
                            'credit_ledger_entry_id' => (string) ($purchase->credit_ledger_entry_id ?? ''),
                            'credit_wallet_id' => (string) ($ledgerEntry?->credit_wallet_id ?? ''),
                        ]);
                    }
                }

                if ($intent->billable_type === Subscription::class) {
                    $subscription = Subscription::query()->find($intent->billable_id);
                    if ($subscription) {
                        $purpose = (string) data_get($intent->meta, 'purpose', 'subscription_renewal');
                        $resolvedProviderPaymentId = trim((string) ($status['id'] ?? $intent->provider_payment_id ?? $providerPaymentId));

                        if ($purpose === 'subscription_initial' || $subscription->status === 'pending_mandate') {
                            $subscriptionLifecycle->onSubscriptionPaymentPaid(
                                subscription: $subscription,
                                providerStatus: $status,
                                providerPaymentId: $resolvedProviderPaymentId
                            );
                        } else {
                            $subscription->provider = 'mollie';
                            $subscription->provider_customer_id = (string) ($status['provider_customer_id'] ?? $subscription->provider_customer_id);
                            $subscription->provider_mandate_id = (string) ($status['provider_mandate_id'] ?? $subscription->provider_mandate_id);
                            $subscription->provider_subscription_id = (string) ($status['provider_subscription_id'] ?? $subscription->provider_subscription_id);
                            $subscription->provider_payment_id = (string) ($status['id'] ?? $subscription->provider_payment_id);
                            $subscription->save();

                            $subscriptionLifecycle->handleRenewalPaid($subscription, $resolvedProviderPaymentId);
                        }

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

                        Log::info('billing.webhook.mollie.subscription_paid', [
                            'provider_payment_id' => $providerPaymentId,
                            'payment_intent_id' => (string) $intent->id,
                            'subscription_id' => (string) $subscription->id,
                            'organization_id' => (string) $subscription->organization_id,
                            'workspace_id' => (string) ($subscription->workspace_id ?? ''),
                            'client_site_id' => (string) ($subscription->client_site_id ?? ''),
                            'plan_id' => (string) ($subscription->plan_id ?? ''),
                            'status' => (string) $subscription->status,
                            'provider_subscription_id' => (string) ($subscription->provider_subscription_id ?? ''),
                            'provider_customer_id' => (string) ($subscription->provider_customer_id ?? ''),
                            'provider_mandate_id' => (string) ($subscription->provider_mandate_id ?? ''),
                            'included_credits_per_interval' => (int) ($subscription->included_credits_per_interval ?? 0),
                        ]);
                    }
                }

                if ($intent->billable_type === SubscriptionPlanChange::class) {
                    $change = SubscriptionPlanChange::query()->find($intent->billable_id);
                    if ($change) {
                        $planChanges->applyAfterPayment($change->fresh(['subscription.organization', 'toPlan', 'fromPlan', 'paymentIntent']));
                    }
                }

                $invoiceCreator->ensureInvoiceForPaidIntent($intent->fresh('billable') ?? $intent, $status);
            }

            if (! empty($status['is_failed']) || ! empty($status['is_canceled']) || ! empty($status['is_expired'])) {
                if ($intent->billable_type === Subscription::class) {
                    $subscription = Subscription::query()->find($intent->billable_id);
                    if ($subscription) {
                        $subscriptionLifecycle->markPastDue($subscription);
                        // Do NOT call refreshProviderState() here: the past_due status reflects
                        // local knowledge about a failed payment that shouldn't be overwritten
                        // by the provider's state which may still report the subscription as active.
                    }
                }

                if ($intent->billable_type === SubscriptionPlanChange::class) {
                    $change = SubscriptionPlanChange::query()->find($intent->billable_id);
                    if ($change && $change->isPending()) {
                        $change->transitionTo(SubscriptionPlanChangeStatus::FAILED, [
                            'blocked_reason' => 'payment_' . (string) $intent->status,
                        ]);
                    }
                }
            }

            if (! empty($status['is_refunded']) && $intent->invoice) {
                $invoices->markRefunded($intent->invoice, (string) $intent->provider_payment_id);
            }

            $event->handled_at = now();
            $event->handler_result = [
                'payment_intent_id' => $intent->id,
                'provider_payment_id' => $providerPaymentId,
                'status' => $intent->status,
            ];
            $event->save();
        } catch (\Throwable $exception) {
            $event->error = $exception->getMessage();
            $event->save();

            Log::error('Mollie webhook handling failed', [
                'provider_payment_id' => $providerPaymentId,
                'intent_id' => $intent->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json(['ok' => true], 200);
    }
}
