<?php

namespace App\Services;

use App\Actions\Billing\RefreshWorkspaceEntitlements;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\CreditPolicyService;
use App\Services\Billing\SubscriptionCheckoutPricing;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SubscriptionLifecycleService
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly CreditWalletService $wallets,
        private readonly BillingSettingsService $settings,
        private readonly RefreshWorkspaceEntitlements $refreshWorkspaceEntitlements,
        private readonly SubscriptionCheckoutPricing $checkoutPricing,
        private readonly CreditPolicyService $creditPolicy
    ) {
    }

    public function startSignup(Organization $organization, Plan $plan, array $billingData = []): array
    {
        if (! $plan->is_active) {
            throw new RuntimeException('Selected plan is not active.');
        }

        return DB::transaction(function () use ($organization, $plan, $billingData): array {
            $siteId = $organization->clientSites()->orderBy('client_sites.created_at')->value('client_sites.id');

            $subscription = Subscription::query()
                ->where('organization_id', $organization->id)
                ->latest('created_at')
                ->first();

            if (! $subscription) {
                $subscription = Subscription::query()->create([
                    'id' => (string) Str::uuid(),
                    'organization_id' => $organization->id,
                    'workspace_id' => $organization->workspaces()->orderBy('created_at')->value('id'),
                    'client_site_id' => $siteId,
                    'plan_id' => $plan->id,
                    'interval' => (string) ($plan->interval ?: 'month'),
                    'price_cents' => (int) ($plan->price_cents ?: $plan->monthly_price_cents),
                    'currency' => (string) $plan->currency,
                    'included_credits_per_interval' => (int) ($plan->included_credits_per_interval ?: $plan->included_credits),
                    'seat_limit' => (int) max(1, ($plan->seat_limit ?: data_get($plan->limits, 'users', 1))),
                    'status' => 'pending_mandate',
                ]);
            } else {
                $subscription->plan_id = $plan->id;
                $subscription->workspace_id = $organization->workspaces()->orderBy('created_at')->value('id');
                $subscription->interval = (string) ($plan->interval ?: 'month');
                $subscription->price_cents = (int) ($plan->price_cents ?: $plan->monthly_price_cents);
                $subscription->currency = (string) $plan->currency;
                $subscription->included_credits_per_interval = (int) ($plan->included_credits_per_interval ?: $plan->included_credits);
                $subscription->seat_limit = (int) max(1, ($plan->seat_limit ?: data_get($plan->limits, 'users', 1)));
                $subscription->status = 'pending_mandate';
                $subscription->save();
            }

            $checkout = $this->checkoutPricing->forInitialSubscription($plan, $subscription);
            $subscriptionMeta = is_array($subscription->meta) ? $subscription->meta : [];
            $subscriptionMeta['initial_checkout'] = [
                'currency' => $checkout['currency'],
                'line_items' => $checkout['line_items'],
                'total_due_today_cents' => $checkout['total_due_today_cents'],
            ];
            $subscriptionMeta['onboarding_required'] = $checkout['onboarding_required'];
            $subscriptionMeta['onboarding_label'] = $checkout['onboarding_label'];
            $subscriptionMeta['onboarding_fee_cents'] = $checkout['onboarding_amount_cents'];
            $subscription->meta = $subscriptionMeta;
            $subscription->save();

            $existingOpenIntent = PaymentIntent::query()
                ->where('billable_type', Subscription::class)
                ->where('billable_id', $subscription->id)
                ->whereIn('status', ['pending', 'open'])
                ->orderByDesc('created_at')
                ->first();

            $existingOpenIntentMatchesCheckout = $existingOpenIntent
                && (int) $existingOpenIntent->amount_cents === (int) $checkout['total_due_today_cents']
                && (array) data_get($existingOpenIntent->meta, 'line_items', []) === (array) $checkout['line_items'];

            if ($existingOpenIntentMatchesCheckout && ! empty($existingOpenIntent->checkout_url)) {
                return ['subscription' => $subscription, 'payment_intent' => $existingOpenIntent];
            }

            $organization->fill([
                'billing_company_name' => (string) ($billingData['company_name'] ?? $organization->billing_company_name ?? $organization->name),
                'billing_address_line1' => $billingData['address_line1'] ?? $organization->billing_address_line1,
                'billing_address_line2' => $billingData['address_line2'] ?? $organization->billing_address_line2,
                'billing_postal_code' => $billingData['postal_code'] ?? $organization->billing_postal_code,
                'billing_city' => $billingData['city'] ?? $organization->billing_city,
                'billing_country_code' => $billingData['country_code'] ?? $organization->billing_country_code,
                'billing_vat_number' => $billingData['vat_number'] ?? $organization->billing_vat_number,
                'billing_kvk_number' => $billingData['kvk_number'] ?? $organization->billing_kvk_number,
            ])->save();

            $intent = PaymentIntent::query()->create([
                'id' => (string) Str::uuid(),
                'billable_type' => Subscription::class,
                'billable_id' => $subscription->id,
                'provider' => config('billing.default_provider', 'mollie'),
                'status' => 'pending',
                'amount_cents' => (int) $checkout['total_due_today_cents'],
                'currency' => (string) $subscription->currency,
                'idempotency_key' => 'subscription_signup:' . $subscription->id . ':' . now()->format('YmdHis'),
                'meta' => [
                    'purpose' => 'subscription_initial',
                    'subscription_id' => (string) $subscription->id,
                    'organization_id' => (string) $organization->id,
                    'line_items' => $checkout['line_items'],
                    'recurring_amount_cents' => $checkout['recurring_amount_cents'],
                    'onboarding_amount_cents' => $checkout['onboarding_amount_cents'],
                    'total_due_today_cents' => $checkout['total_due_today_cents'],
                    'onboarding_required' => $checkout['onboarding_required'],
                    'onboarding_label' => $checkout['onboarding_label'],
                ],
            ]);

            $provider = $this->providers->get((string) $intent->provider);
            $result = $provider->createSubscriptionPaymentIntent($subscription, $intent);

            $intent->provider_payment_id = $result['provider_payment_id'];
            $intent->checkout_url = $result['checkout_url'] ?? null;
            $intent->status = $result['status'] ?? $intent->status;
            $intent->save();

            if (! empty($result['provider_customer_id'])) {
                $subscription->provider_customer_id = (string) $result['provider_customer_id'];
            }
            if (! empty($result['provider_mandate_id'])) {
                $subscription->provider_mandate_id = (string) $result['provider_mandate_id'];
            }
            $subscription->provider_payment_id = (string) $intent->provider_payment_id;
            $subscription->save();

            Log::info('billing.subscription.signup.checkout_created', [
                'organization_id' => (string) $organization->id,
                'subscription_id' => (string) $subscription->id,
                'workspace_id' => (string) ($subscription->workspace_id ?? ''),
                'client_site_id' => (string) ($subscription->client_site_id ?? ''),
                'plan_id' => (string) ($subscription->plan_id ?? ''),
                'plan_key' => (string) ($plan->key ?? ''),
                'payment_intent_id' => (string) $intent->id,
                'provider' => (string) $intent->provider,
                'provider_payment_id' => (string) ($intent->provider_payment_id ?? ''),
                'status' => (string) $subscription->status,
            ]);

            return ['subscription' => $subscription, 'payment_intent' => $intent];
        });
    }

    public function onSubscriptionPaymentPaid(
        Subscription $subscription,
        array $providerStatus,
        ?string $providerPaymentId = null
    ): Subscription
    {
        return DB::transaction(function () use ($subscription, $providerStatus, $providerPaymentId): Subscription {
            $subscription->status = 'pending_mandate';
            $subscription->provider = 'mollie';
            $subscription->provider_customer_id = (string) ($providerStatus['provider_customer_id'] ?? $subscription->provider_customer_id);
            $subscription->provider_mandate_id = (string) ($providerStatus['provider_mandate_id'] ?? $subscription->provider_mandate_id);
            $subscription->provider_payment_id = (string) ($providerStatus['id'] ?? $subscription->provider_payment_id);
            $subscription->mandate_last_checked_at = now();
            $subscription->save();

            $resolvedPaymentId = trim((string) ($providerPaymentId ?: ($providerStatus['id'] ?? '')));
            $subscription = $this->markInitialCheckoutComponentsPaid($subscription, $resolvedPaymentId);
            $activated = $this->activateRecurringIfMandateReady(
                subscription: $subscription,
                grantCredits: true,
                providerPaymentId: $resolvedPaymentId !== '' ? $resolvedPaymentId : null
            );

            if (! in_array((string) $activated->status, ['active', 'trialing'], true) && $resolvedPaymentId !== '') {
                $meta = is_array($activated->meta) ? $activated->meta : [];
                $meta['pending_initial_credit_payment_id'] = $resolvedPaymentId;
                $activated->meta = $meta;
                $activated->save();
            }

            return $activated;
        });
    }

    public function markInitialCheckoutComponentsPaid(Subscription $subscription, ?string $providerPaymentId = null): Subscription
    {
        $providerPaymentId = trim((string) ($providerPaymentId ?? ''));

        if ($providerPaymentId === '') {
            return $subscription;
        }

        $intent = PaymentIntent::query()
            ->where('billable_type', Subscription::class)
            ->where('billable_id', $subscription->id)
            ->where('provider_payment_id', $providerPaymentId)
            ->latest('created_at')
            ->first();

        if (! $intent) {
            return $subscription;
        }

        $meta = is_array($subscription->meta) ? $subscription->meta : [];
        $lineItems = collect(is_array(data_get($intent->meta, 'line_items')) ? data_get($intent->meta, 'line_items') : []);
        $onboardingLine = $lineItems->first(static fn (mixed $item): bool => (string) data_get($item, 'code') === 'onboarding');

        if (! is_array($onboardingLine) || ((int) data_get($onboardingLine, 'amount_cents', 0)) <= 0) {
            return $subscription;
        }

        if (! empty($meta['onboarding_paid_at']) || ! empty($meta['onboarding_paid'])) {
            return $subscription;
        }

        $meta['onboarding_paid'] = true;
        $meta['onboarding_paid_at'] = now()->toIso8601String();
        $meta['onboarding_payment_intent_id'] = (string) $intent->id;
        $meta['onboarding_provider_payment_id'] = $providerPaymentId;
        $meta['onboarding_label'] = (string) data_get($onboardingLine, 'label', ($meta['onboarding_label'] ?? 'Guided Onboarding'));
        $meta['onboarding_fee_cents'] = (int) data_get($onboardingLine, 'amount_cents', ($meta['onboarding_fee_cents'] ?? 0));
        $subscription->meta = $meta;
        $subscription->save();

        return $subscription;
    }

    public function activateRecurringIfMandateReady(
        Subscription $subscription,
        bool $grantCredits = false,
        ?string $providerPaymentId = null
    ): Subscription
    {
        $provider = $this->providers->get((string) ($subscription->provider ?: config('billing.default_provider', 'mollie')));

        $mandateId = (string) ($subscription->provider_mandate_id ?? '');
        if ($mandateId === '' && $subscription->provider_customer_id) {
            $mandateId = (string) ($provider->fetchActiveMandateId((string) $subscription->provider_customer_id) ?? '');
        }

        if ($mandateId === '') {
            $subscription->status = 'pending_mandate';
            $subscription->status_reason = 'waiting_for_mandate_activation';
            $subscription->mandate_last_checked_at = now();

            // Store payment ID for later credit grant when mandate becomes active.
            // This ensures credits are granted even when mandate activation is delayed.
            $resolvedPaymentId = trim((string) ($providerPaymentId ?? ''));
            if ($grantCredits && $resolvedPaymentId !== '') {
                $meta = is_array($subscription->meta) ? $subscription->meta : [];
                // Only store if not already set (preserve existing pending payment ID)
                if (empty($meta['pending_initial_credit_payment_id'])) {
                    $meta['pending_initial_credit_payment_id'] = $resolvedPaymentId;
                    $subscription->meta = $meta;
                }
            }

            $subscription->save();

            Log::info('billing.subscription.mandate_pending', [
                'subscription_id' => (string) $subscription->id,
                'organization_id' => (string) ($subscription->organization_id ?? ''),
                'workspace_id' => (string) ($subscription->workspace_id ?? ''),
                'client_site_id' => (string) ($subscription->client_site_id ?? ''),
                'plan_id' => (string) ($subscription->plan_id ?? ''),
                'provider_customer_id' => (string) ($subscription->provider_customer_id ?? ''),
                'provider_mandate_id' => (string) ($subscription->provider_mandate_id ?? ''),
                'pending_credit_payment_id' => $resolvedPaymentId !== '' ? $resolvedPaymentId : null,
            ]);

            return $subscription;
        }

        if (! $subscription->provider_subscription_id) {
            $created = $provider->createRecurringSubscription($subscription);
            $subscription->provider_subscription_id = (string) ($created['provider_subscription_id'] ?? $subscription->provider_subscription_id);
        }

        $start = Carbon::now()->startOfDay();
        $end = $this->nextPeriodEnd($start, (string) ($subscription->interval ?: 'month'));

        $subscription->provider_mandate_id = $mandateId;
        $subscription->status = 'active';
        $subscription->status_reason = null;
        $subscription->current_period_start = $subscription->current_period_start ?: $start;
        $subscription->current_period_end = $subscription->current_period_end ?: $end;
        $subscription->next_payment_at = $subscription->next_payment_at ?: $end;
        $subscription->billing_cycle_anchor = $subscription->billing_cycle_anchor ?: $subscription->current_period_start;
        $subscription->grace_period_ends_at = null;
        $subscription->suspended_at = null;
        $subscription->save();

        $subscription = $this->refreshProviderState($subscription);

        // Resolve payment ID for credit grant - check stored pending payment ID if needed.
        $resolvedProviderPaymentId = trim((string) ($providerPaymentId ?? ''));

        // If no payment ID was provided, check for a pending one stored in meta.
        // This handles the case where mandate activation was delayed after initial payment.
        if ($resolvedProviderPaymentId === '') {
            $pendingMeta = is_array($subscription->meta) ? $subscription->meta : [];
            $pendingPaymentId = trim((string) ($pendingMeta['pending_initial_credit_payment_id'] ?? ''));
            if ($pendingPaymentId !== '') {
                $grantCredits = true;
                $resolvedProviderPaymentId = $pendingPaymentId;
                unset($pendingMeta['pending_initial_credit_payment_id']);
                $subscription->meta = $pendingMeta;
                $subscription->save();
            }
        }

        if ($grantCredits) {
            $this->grantIncludedCreditsForPaidRenewal(
                subscription: $subscription,
                providerPaymentId: $resolvedProviderPaymentId
            );
        }

        $this->refreshWorkspaceEntitlements->forSubscription(
            $subscription->fresh(['plan', 'workspace', 'organization.workspaces']) ?? $subscription
        );

        Log::info('billing.subscription.activated', [
            'subscription_id' => (string) $subscription->id,
            'organization_id' => (string) ($subscription->organization_id ?? ''),
            'workspace_id' => (string) ($subscription->workspace_id ?? ''),
            'client_site_id' => (string) ($subscription->client_site_id ?? ''),
            'plan_id' => (string) ($subscription->plan_id ?? ''),
            'provider_subscription_id' => (string) ($subscription->provider_subscription_id ?? ''),
            'provider_mandate_id' => (string) ($subscription->provider_mandate_id ?? ''),
            'current_period_start' => optional($subscription->current_period_start)?->toIso8601String(),
            'current_period_end' => optional($subscription->current_period_end)?->toIso8601String(),
            'included_credits_per_interval' => (int) ($subscription->included_credits_per_interval ?? 0),
        ]);

        return $subscription;
    }

    public function handleRenewalPaid(Subscription $subscription, ?string $providerPaymentId = null): Subscription
    {
        $start = $this->resolveRenewalPeriodStart($subscription);
        $end = $this->nextPeriodEnd($start, (string) ($subscription->interval ?: 'month'));

        $subscription->current_period_start = $start;
        $subscription->current_period_end = $end;
        $subscription->next_payment_at = $end;
        $subscription->billing_cycle_anchor = $subscription->billing_cycle_anchor ?: $start;
        $subscription->status = 'active';
        $subscription->status_reason = null;
        $subscription->grace_period_ends_at = null;
        $subscription->suspended_at = null;
        $subscription->save();

        if ($subscription->pending_plan_id) {
            $this->applyPendingPlanIfAllowed($subscription);
        }

        $subscription = $this->refreshProviderState($subscription);
        $this->grantIncludedCreditsForPaidRenewal(
            subscription: $subscription,
            providerPaymentId: (string) ($providerPaymentId ?? '')
        );

        $this->refreshWorkspaceEntitlements->forSubscription(
            $subscription->fresh(['plan', 'workspace', 'organization.workspaces']) ?? $subscription
        );

        return $subscription;
    }

    public function refreshProviderState(Subscription $subscription): Subscription
    {
        $providerName = (string) ($subscription->provider ?: config('billing.default_provider', 'mollie'));
        if ($providerName === '') {
            return $subscription;
        }

        $customerId = trim((string) ($subscription->provider_customer_id ?? ''));
        $providerSubscriptionId = trim((string) ($subscription->provider_subscription_id ?? ''));

        if ($customerId === '' || $providerSubscriptionId === '') {
            return $subscription;
        }

        $provider = $this->providers->get($providerName);
        if (! method_exists($provider, 'fetchSubscriptionDetails')) {
            return $subscription;
        }

        try {
            /** @var array<string,mixed> $snapshot */
            $snapshot = $provider->fetchSubscriptionDetails($customerId, $providerSubscriptionId);

            $status = strtolower(trim((string) ($snapshot['status'] ?? '')));
            $nextPaymentAt = $this->parseProviderDate($snapshot['next_payment_at'] ?? null);
            $canceledAt = $this->parseProviderDate($snapshot['canceled_at'] ?? null);

            if ($nextPaymentAt) {
                $subscription->next_payment_at = $nextPaymentAt;
                if (! $subscription->current_period_end || $nextPaymentAt->greaterThan($subscription->current_period_end)) {
                    $subscription->current_period_end = $nextPaymentAt;
                }
            }

            if ($status !== '') {
                if (in_array($status, ['active', 'trialing'], true)) {
                    if (in_array((string) $subscription->status, ['past_due', 'pending_mandate', 'suspended'], true)) {
                        $subscription->status = 'active';
                        $subscription->status_reason = null;
                        $subscription->grace_period_ends_at = null;
                        $subscription->suspended_at = null;
                    }
                } elseif (in_array($status, ['canceled', 'cancelled'], true)) {
                    $subscription->canceled_at = $subscription->canceled_at ?: ($canceledAt ?: now());
                    $subscription->next_payment_at = null;

                    if ($subscription->current_period_end && $subscription->current_period_end->isFuture()) {
                        if (! in_array((string) $subscription->status, ['active', 'trialing', 'past_due'], true)) {
                            $subscription->status = 'active';
                        }
                        $subscription->status_reason = 'canceled_effective_period_end';
                    } else {
                        $subscription->status = 'canceled';
                        $subscription->status_reason = 'provider_canceled';
                    }
                } elseif (in_array($status, ['suspended', 'paused'], true)) {
                    $subscription->status = 'suspended';
                    $subscription->status_reason = 'provider_suspended';
                    $subscription->suspended_at = $subscription->suspended_at ?: now();
                }
            }

            if ($subscription->isDirty()) {
                $subscription->save();
            }
        } catch (\Throwable $exception) {
            Log::warning('billing.subscription.refresh_provider_state_failed', [
                'subscription_id' => (string) $subscription->id,
                'provider' => $providerName,
                'error' => $exception->getMessage(),
            ]);
        }

        return $subscription->fresh() ?? $subscription;
    }

    public function markPastDue(Subscription $subscription, string $reason = 'renewal_payment_failed'): Subscription
    {
        $graceDays = (int) data_get($this->settings->getDunningDefaults(), 'grace_days', 7);

        $subscription->status = 'past_due';
        $subscription->status_reason = $reason;
        $subscription->grace_period_ends_at = now()->addDays(max(1, $graceDays));
        $subscription->save();

        return $subscription;
    }

    public function suspendIfGraceExpired(Subscription $subscription): Subscription
    {
        if ($subscription->status !== 'past_due') {
            return $subscription;
        }

        if (! $subscription->grace_period_ends_at || $subscription->grace_period_ends_at->isFuture()) {
            return $subscription;
        }

        $subscription->status = 'suspended';
        $subscription->status_reason = 'grace_period_expired';
        $subscription->suspended_at = now();
        $subscription->save();

        return $subscription;
    }

    public function applyPendingPlanIfAllowed(Subscription $subscription): Subscription
    {
        if (! $subscription->pending_plan_id) {
            return $subscription;
        }

        $newPlan = Plan::query()->find($subscription->pending_plan_id);
        if (! $newPlan || ! $newPlan->is_active) {
            $subscription->status_reason = 'pending_plan_invalid';
            $subscription->save();

            return $subscription;
        }

        $activeUsers = (int) $subscription->organization?->users()->where('active', true)->count();
        $newSeatLimit = (int) max(1, ($newPlan->seat_limit ?: data_get($newPlan->limits, 'users', 1)));

        if ($activeUsers > $newSeatLimit) {
            $subscription->status_reason = 'pending_plan_blocked_seat_non_compliance';
            $subscription->save();

            return $subscription;
        }

        $subscription->plan_id = $newPlan->id;
        $subscription->pending_plan_id = null;
        $subscription->interval = (string) ($newPlan->interval ?: 'month');
        $subscription->price_cents = (int) ($newPlan->price_cents ?: $newPlan->monthly_price_cents);
        $subscription->currency = (string) $newPlan->currency;
        $subscription->included_credits_per_interval = (int) ($newPlan->included_credits_per_interval ?: $newPlan->included_credits);
        $subscription->seat_limit = $newSeatLimit;
        $subscription->status_reason = null;
        $subscription->save();
        $this->refreshWorkspaceEntitlements->forSubscription(
            $subscription->fresh(['plan', 'workspace', 'organization.workspaces']) ?? $subscription
        );

        return $subscription;
    }

    private function grantIncludedCreditsForPaidRenewal(Subscription $subscription, string $providerPaymentId): void
    {
        $providerPaymentId = trim($providerPaymentId);
        if ($providerPaymentId === '') {
            return;
        }

        if (! $subscription->client_site_id) {
            return;
        }

        if ($subscription->canceled_at || $subscription->status === 'canceled') {
            return;
        }

        $amount = (int) ($subscription->included_credits_per_interval ?? 0);
        if ($amount <= 0) {
            return;
        }

        $subscription->loadMissing('plan');
        $rolloverPolicy = strtolower(trim((string) ($subscription->plan?->credit_rollover_policy ?? 'none')));
        if (! in_array($rolloverPolicy, ['none', 'limited', 'unlimited'], true)) {
            $rolloverPolicy = 'none';
        }

        $expiryDays = $subscription->plan?->credit_expiry_days !== null
            ? max(1, (int) $subscription->plan?->credit_expiry_days)
            : null;

        $expiresAt = $this->creditPolicy->resolveSubscriptionGrantExpiryAt(
            $subscription,
            $subscription->current_period_start?->copy(),
            $subscription->current_period_end?->copy()
        );

        $idempotencyKey = sprintf('allowance:sub:%s:payment:%s', (string) $subscription->id, $providerPaymentId);

        $this->wallets->addWorkspaceCredits(
            workspaceId: (string) $subscription->workspace_id,
            amount: $amount,
            type: CreditWalletService::TYPE_ALLOWANCE,
            meta: [
                'ledger_type' => 'subscription_renewal',
                'reference' => $providerPaymentId,
                'provider_payment_id' => $providerPaymentId,
                'monthly_credit_amount' => $amount,
                'credit_rollover_policy' => $rolloverPolicy,
                'credit_expiry_days' => $expiryDays,
                'credit_rollover_monthly_cycles' => (int) ($subscription->plan?->credit_rollover_monthly_cycles ?? 3),
                'plan_id' => (string) $subscription->plan_id,
                'subscription_id' => (string) $subscription->id,
                'interval' => (string) $subscription->interval,
                'period_start' => $subscription->current_period_start?->toIso8601String(),
                'period_end' => $subscription->current_period_end?->toIso8601String(),
            ],
            sourceType: Subscription::class,
            sourceId: (string) $subscription->id,
            expiresAt: $expiresAt,
            idempotencyKey: $idempotencyKey,
            preferredClientSiteId: (string) $subscription->client_site_id
        );
    }

    private function resolveRenewalPeriodStart(Subscription $subscription): Carbon
    {
        $now = Carbon::now()->startOfDay();
        $currentPeriodEnd = $subscription->current_period_end?->copy()->startOfDay();

        if ($currentPeriodEnd && $currentPeriodEnd->lessThanOrEqualTo($now->copy()->addDay())) {
            return $currentPeriodEnd;
        }

        return $now;
    }

    private function parseProviderDate(mixed $value): ?Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return strlen($trimmed) <= 10
                ? Carbon::createFromFormat('Y-m-d', $trimmed)->startOfDay()
                : Carbon::parse($trimmed);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nextPeriodEnd(Carbon $start, string $interval): Carbon
    {
        return $interval === 'year'
            ? $start->copy()->addYear()->startOfDay()
            : $start->copy()->addMonth()->startOfDay();
    }
}
