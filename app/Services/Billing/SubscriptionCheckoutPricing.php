<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Support\OnboardingFee;

class SubscriptionCheckoutPricing
{
    /**
     * @return array{
     *   currency:string,
     *   recurring_amount_cents:int,
     *   onboarding_amount_cents:int,
     *   total_due_today_cents:int,
     *   onboarding_required:bool,
     *   onboarding_charged:bool,
     *   onboarding_label:string,
     *   onboarding_description:string,
     *   line_items:array<int,array{code:string,type:string,label:string,amount_cents:int,description:string}>
     * }
     */
    public function forInitialSubscription(Plan $plan, ?Subscription $subscription = null): array
    {
        $recurringAmountCents = max(0, (int) ($plan->price_cents ?: $plan->monthly_price_cents));
        $onboarding = $this->resolveOnboarding($plan);
        $shouldChargeOnboarding = $this->shouldChargeOnboarding($plan, $subscription);
        $onboardingAmountCents = $shouldChargeOnboarding ? $onboarding['fee_cents'] : 0;

        $lineItems = [[
            'code' => 'subscription',
            'type' => 'recurring',
            'label' => trim((string) ($plan->name ?: 'Plan')) . ' subscription',
            'amount_cents' => $recurringAmountCents,
            'description' => sprintf(
                '%s billed monthly.',
                trim((string) ($plan->name ?: 'Plan'))
            ),
        ]];

        if ($onboardingAmountCents > 0) {
            $lineItems[] = [
                'code' => 'onboarding',
                'type' => 'one_time',
                'label' => $onboarding['label'],
                'amount_cents' => $onboardingAmountCents,
                'description' => $onboarding['description'] !== ''
                    ? $onboarding['description']
                    : sprintf('%s is charged once during the initial checkout only.', $onboarding['label']),
            ];
        }

        return [
            'currency' => strtoupper(trim((string) ($plan->currency ?: 'EUR'))),
            'recurring_amount_cents' => $recurringAmountCents,
            'onboarding_amount_cents' => $onboardingAmountCents,
            'total_due_today_cents' => $recurringAmountCents + $onboardingAmountCents,
            'onboarding_required' => $onboarding['required'],
            'onboarding_charged' => $onboardingAmountCents > 0,
            'onboarding_label' => $onboarding['label'],
            'onboarding_description' => $onboarding['description'],
            'line_items' => $lineItems,
        ];
    }

    public function shouldChargeOnboarding(Plan $plan, ?Subscription $subscription = null): bool
    {
        // Global waiver takes precedence
        if (OnboardingFee::isWaived()) {
            return false;
        }

        $onboarding = $this->resolveOnboarding($plan);

        if (! $onboarding['required'] || $onboarding['fee_cents'] <= 0) {
            return false;
        }

        if (! $subscription) {
            return true;
        }

        $meta = is_array($subscription->meta) ? $subscription->meta : [];

        if (! empty($meta['onboarding_paid_at']) || ! empty($meta['onboarding_paid'])) {
            return false;
        }

        if (! empty($meta['onboarding_waived'])) {
            return false;
        }

        if (trim((string) ($subscription->provider_subscription_id ?? '')) !== '') {
            return false;
        }

        return ! in_array((string) $subscription->status, ['active', 'trialing', 'past_due', 'suspended', 'canceled'], true);
    }

    /**
     * @return array{required:bool,label:string,fee_cents:int,description:string}
     */
    public function resolveOnboarding(Plan $plan): array
    {
        $onboarding = $plan->onboardingData();

        return [
            'required' => (bool) $onboarding['required'],
            'label' => $this->normalizeLabel((string) $onboarding['label']),
            'fee_cents' => max(0, (int) $onboarding['fee_cents']),
            'description' => trim((string) $onboarding['description']),
        ];
    }

    private function normalizeLabel(string $label): string
    {
        $label = trim($label);

        if ($label === '') {
            return 'Guided Onboarding';
        }

        return preg_replace_callback('/\b([a-z])/', static fn (array $m): string => strtoupper($m[1]), strtolower($label)) ?? $label;
    }
}
