<?php

namespace App\Services;

use App\Actions\Billing\RefreshWorkspaceEntitlements;
use App\Enums\Billing\PlanChangeTiming;
use App\Enums\Billing\SubscriptionPlanChangeStatus;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Services\Billing\PlanProrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PlanChangeService
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly BillingSettingsService $settings,
        private readonly InvoiceService $invoices,
        private readonly RefreshWorkspaceEntitlements $refreshWorkspaceEntitlements,
        private readonly PlanProrationService $proration,
    ) {
    }

    public function requestChange(Subscription $subscription, Plan $targetPlan, ?PlanChangeTiming $timing = null): array
    {
        if (! $targetPlan->is_active) {
            throw new RuntimeException('Target plan is not active.');
        }

        if ((string) $subscription->plan_id === (string) $targetPlan->id) {
            throw new RuntimeException('Subscription already on selected plan.');
        }

        $currentPrice = (int) $subscription->price_cents;
        $targetPrice = (int) ($targetPlan->price_cents ?: $targetPlan->monthly_price_cents);
        $isUpgrade = $targetPrice > $currentPrice;

        $defaults = $this->settings->getPlanChangeDefaults();
        $resolvedTiming = $this->resolveTiming($timing, $isUpgrade, $defaults);
        $resolvedStrategy = $resolvedTiming->toStrategyValue();

        if ($isUpgrade && $resolvedTiming->isImmediateProrated() && ! (bool) data_get($defaults, 'immediate_upgrades_enabled', true)) {
            throw new RuntimeException('Immediate upgrades are currently disabled.');
        }

        if (! $isUpgrade && $resolvedTiming->isImmediateProrated() && ! (bool) data_get($defaults, 'allow_immediate_downgrade', false)) {
            throw new RuntimeException('Immediate downgrade is disabled.');
        }

        return DB::transaction(function () use ($subscription, $targetPlan, $resolvedTiming, $resolvedStrategy, $isUpgrade) {
            $this->replacePendingScheduledChange($subscription);

            $change = SubscriptionPlanChange::query()->create([
                'id' => (string) Str::uuid(),
                'subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id,
                'from_plan_id' => $subscription->plan_id,
                'to_plan_id' => $targetPlan->id,
                'strategy' => $resolvedStrategy,
                'status' => SubscriptionPlanChangeStatus::PENDING,
                'currency' => (string) ($subscription->currency ?: $targetPlan->currency ?: 'EUR'),
                'effective_at' => $resolvedTiming === PlanChangeTiming::NEXT_PERIOD
                    ? ($subscription->current_period_end ?: now())
                    : now(),
                'meta' => [
                    'is_upgrade' => $isUpgrade,
                    'timing' => $resolvedTiming->value,
                ],
            ]);

            if ($resolvedTiming === PlanChangeTiming::NEXT_PERIOD) {
                $subscription->pending_plan_id = $targetPlan->id;
                $subscription->status_reason = $isUpgrade
                    ? 'upgrade_scheduled_next_period'
                    : 'downgrade_scheduled_next_period';
                $subscription->save();

                return ['change' => $change, 'payment_intent' => null, 'checkout_url' => null];
            }

            $proration = $this->proration->calculate($subscription, $targetPlan);
            $change->proration_amount_cents = max(0, (int) ($proration['amount_due_cents'] ?? 0));
            $change->meta = array_merge((array) $change->meta, [
                'change_type' => $isUpgrade ? 'upgrade' : 'downgrade',
                'billing_action' => $isUpgrade ? 'immediate_upgrade' : 'immediate_downgrade',
                'proration' => $proration,
            ]);
            $change->save();

            if ($change->proration_amount_cents <= 0) {
                $this->applyImmediatePlan($subscription, $targetPlan);
                $change->transitionTo(SubscriptionPlanChangeStatus::APPLIED, [
                    'applied_at' => now(),
                ]);

                return ['change' => $change, 'payment_intent' => null, 'checkout_url' => null];
            }

            $intent = PaymentIntent::query()->create([
                'id' => (string) Str::uuid(),
                'billable_type' => SubscriptionPlanChange::class,
                'billable_id' => $change->id,
                'provider' => config('billing.default_provider', 'mollie'),
                'status' => 'pending',
                'amount_cents' => $change->proration_amount_cents,
                'currency' => $change->currency,
                'idempotency_key' => 'plan_change:' . $change->id,
                'meta' => [
                    'purpose' => 'plan_change_adjustment',
                    'subscription_id' => (string) $subscription->id,
                    'plan_change_id' => (string) $change->id,
                    'workspace_id' => (string) ($subscription->workspace_id ?? ''),
                    'from_plan' => (string) ($subscription->plan?->key ?: $subscription->plan?->slug ?: $change->from_plan_id),
                    'to_plan' => (string) ($targetPlan->key ?: $targetPlan->slug ?: $targetPlan->id),
                    'change_type' => $isUpgrade ? 'upgrade' : 'downgrade',
                    'billing_action' => $isUpgrade ? 'immediate_upgrade' : 'immediate_downgrade',
                ],
            ]);

            $provider = $this->providers->get((string) $intent->provider);
            $result = $provider->createSubscriptionPaymentIntent($subscription, $intent);

            $intent->provider_payment_id = $result['provider_payment_id'];
            $intent->checkout_url = $result['checkout_url'] ?? null;
            $intent->status = $result['status'] ?? $intent->status;
            $intent->save();

            $isRecurring = ! empty($result['is_recurring']);
            $hasCheckoutUrl = ! empty($intent->checkout_url);

            // For recurring payments: Mollie charges the mandate directly (no checkout needed)
            // The payment status will be 'open' or 'pending' initially, then 'paid' via webhook
            // For first payments: We need a checkout URL for the user to complete payment
            if (! $hasCheckoutUrl && ! $isRecurring && $intent->status !== 'paid') {
                throw new RuntimeException('Payment provider did not return a checkout URL for immediate plan change.');
            }

            $change->payment_intent_id = $intent->id;
            $change->transitionTo(SubscriptionPlanChangeStatus::PENDING_PAYMENT);

            // For recurring payments without checkout, payment is in progress
            // It will complete via webhook, or may already be 'paid'
            if ($intent->status === 'paid') {
                $this->applyImmediatePlan($subscription, $targetPlan);
                $change->transitionTo(SubscriptionPlanChangeStatus::APPLIED, [
                    'applied_at' => now(),
                ]);
            }

            Log::info('billing.plan_change.payment_initiated', [
                'plan_change_id' => (string) $change->id,
                'subscription_id' => (string) $subscription->id,
                'payment_intent_id' => (string) $intent->id,
                'is_recurring' => $isRecurring,
                'has_checkout_url' => $hasCheckoutUrl,
                'payment_status' => (string) $intent->status,
            ]);

            return ['change' => $change, 'payment_intent' => $intent, 'checkout_url' => $intent->checkout_url];
        });
    }

    public function applyAfterPayment(SubscriptionPlanChange $change): SubscriptionPlanChange
    {
        if ($change->status === SubscriptionPlanChangeStatus::APPLIED) {
            return $change;
        }

        if (! $change->isPending()) {
            return $change;
        }

        if ($change->paymentIntent && (string) $change->paymentIntent->status !== 'paid') {
            return $change;
        }

        $subscription = $change->subscription()->with('organization')->firstOrFail();
        $targetPlan = $change->toPlan;

        if (! $targetPlan || ! $targetPlan->is_active) {
            $change->transitionTo(SubscriptionPlanChangeStatus::FAILED, [
                'blocked_reason' => 'target_plan_invalid',
            ]);

            return $change;
        }

        $this->applyImmediatePlan($subscription, $targetPlan);
        $change->transitionTo(SubscriptionPlanChangeStatus::APPLIED, [
            'applied_at' => now(),
            'blocked_reason' => null,
        ]);

        if ($change->paymentIntent) {
            $this->invoices->createForPaymentIntent($change->paymentIntent->fresh('billable.subscription.organization'));
        }

        Log::info('billing.plan_change.applied_after_payment', [
            'plan_change_id' => (string) $change->id,
            'subscription_id' => (string) $subscription->id,
            'organization_id' => (string) ($subscription->organization_id ?? ''),
            'from_plan_id' => (string) ($change->from_plan_id ?? ''),
            'to_plan_id' => (string) ($change->to_plan_id ?? ''),
            'payment_intent_id' => (string) ($change->payment_intent_id ?? ''),
        ]);

        return $change;
    }

    private function applyImmediatePlan(Subscription $subscription, Plan $targetPlan): void
    {
        $subscription->plan_id = $targetPlan->id;
        $subscription->pending_plan_id = null;
        $subscription->interval = (string) ($targetPlan->interval ?: 'month');
        $subscription->price_cents = (int) ($targetPlan->price_cents ?: $targetPlan->monthly_price_cents);
        $subscription->currency = (string) $targetPlan->currency;
        $subscription->included_credits_per_interval = (int) ($targetPlan->included_credits_per_interval ?: $targetPlan->included_credits);
        $subscription->seat_limit = (int) max(1, ($targetPlan->seat_limit ?: data_get($targetPlan->limits, 'users', 1)));
        $subscription->status_reason = 'plan_changed_immediate';
        $subscription->save();
        $this->refreshWorkspaceEntitlements->forSubscription(
            $subscription->fresh(['plan', 'workspace', 'organization.workspaces']) ?? $subscription
        );
    }

    private function resolveTiming(?PlanChangeTiming $timing, bool $isUpgrade, array $defaults): PlanChangeTiming
    {
        if ($timing !== null) {
            return $timing;
        }

        $fallback = (string) ($isUpgrade
            ? data_get($defaults, 'upgrade_strategy', 'immediate_proration')
            : data_get($defaults, 'downgrade_strategy', 'next_period'));

        $resolved = PlanChangeTiming::fromLegacyStrategy($fallback);
        if ($resolved === null) {
            throw new RuntimeException('Invalid plan change strategy.');
        }

        return $resolved;
    }

    private function replacePendingScheduledChange(Subscription $subscription): void
    {
        SubscriptionPlanChange::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('status', [
                SubscriptionPlanChangeStatus::PENDING->value,
                SubscriptionPlanChangeStatus::PENDING_PAYMENT->value,
            ])
            ->update([
                'status' => SubscriptionPlanChangeStatus::BLOCKED->value,
                'blocked_reason' => 'replaced_by_new_request',
                'updated_at' => now(),
            ]);

        if ($subscription->pending_plan_id !== null) {
            $subscription->pending_plan_id = null;
            if (in_array((string) $subscription->status_reason, ['upgrade_scheduled_next_period', 'downgrade_scheduled_next_period'], true)) {
                $subscription->status_reason = null;
            }
            $subscription->save();
        }
    }
}
