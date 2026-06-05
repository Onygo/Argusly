<?php

namespace App\Services\Billing;

use App\Models\CreditPack;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

class CreditPolicyService
{
    public function resolveSubscriptionGrantExpiryAt(
        Subscription $subscription,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null
    ): ?Carbon {
        $subscription->loadMissing('plan');

        $rolloverPolicy = strtolower(trim((string) ($subscription->plan?->credit_rollover_policy ?? 'none')));
        if (! in_array($rolloverPolicy, ['none', 'limited', 'unlimited'], true)) {
            $rolloverPolicy = 'none';
        }

        if ($rolloverPolicy === 'unlimited') {
            return null;
        }

        $periodStart ??= $subscription->current_period_start?->copy()->startOfDay();
        $periodEnd ??= $subscription->current_period_end?->copy()->startOfDay();
        $interval = strtolower(trim((string) ($subscription->interval ?: $subscription->plan?->interval ?: 'month')));

        if ($rolloverPolicy === 'limited' && $interval === 'month') {
            $cycles = max(1, (int) ($subscription->plan?->credit_rollover_monthly_cycles ?? 3));
            $anchor = $periodStart?->copy()->startOfDay() ?? now()->startOfDay();

            return $anchor->addMonthsNoOverflow($cycles)->startOfDay();
        }

        if ($rolloverPolicy === 'limited') {
            $expiryDays = $subscription->plan?->credit_expiry_days !== null
                ? max(1, (int) $subscription->plan?->credit_expiry_days)
                : null;

            if ($expiryDays !== null) {
                return ($periodStart?->copy() ?? now())->addDays($expiryDays)->endOfDay();
            }
        }

        return $periodEnd;
    }

    public function resolvePackExpiryAt(CreditPack $pack, ?Carbon $paidAt = null): ?Carbon
    {
        if ((bool) $pack->never_expires) {
            return null;
        }

        return ($paidAt?->copy() ?? now())
            ->addMonthsNoOverflow(max(1, (int) ($pack->expires_in_months ?? 12)))
            ->endOfDay();
    }
}
