<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\BillingSettingsService;
use Illuminate\Support\Carbon;

class PlanProrationService
{
    public function __construct(private readonly BillingSettingsService $settings)
    {
    }

    /**
     * @return array{
     *     charge_mode:string,
     *     is_upgrade:bool,
     *     current_price_cents:int,
     *     target_price_cents:int,
     *     unused_current_value_cents:int,
     *     remaining_target_value_cents:int,
     *     amount_due_cents:int,
     *     currency:string,
     *     remaining_ratio:float,
     *     summary:string
     * }
     */
    public function calculate(Subscription $subscription, Plan $targetPlan, ?Carbon $asOf = null): array
    {
        $asOf ??= now();

        $currentPrice = (int) ($subscription->price_cents ?? 0);
        $targetPrice = (int) ($targetPlan->price_cents ?: $targetPlan->monthly_price_cents ?: 0);
        $currency = (string) ($subscription->currency ?: $targetPlan->currency ?: 'EUR');
        $isUpgrade = $targetPrice > $currentPrice;

        $remainingRatio = $this->remainingRatio($subscription, $asOf);
        $unusedCurrentValue = (int) round($currentPrice * $remainingRatio);
        $remainingTargetValue = (int) round($targetPrice * $remainingRatio);

        $defaults = $this->settings->getPlanChangeDefaults();
        $chargeMode = strtolower((string) data_get($defaults, 'upgrade_charge_mode', 'prorated_difference'));
        $prorationEnabled = (bool) data_get($defaults, 'prorated_upgrades_enabled', true);

        if (! $isUpgrade) {
            $amountDue = 0;
        } elseif (! $prorationEnabled) {
            $amountDue = $this->amountForChargeMode(
                chargeMode: $chargeMode,
                targetPrice: $targetPrice,
                currentPrice: $currentPrice,
                remainingTargetValue: $remainingTargetValue,
                unusedCurrentValue: $unusedCurrentValue,
            );
        } else {
            $amountDue = max(0, $remainingTargetValue - $unusedCurrentValue);
        }

        return [
            'charge_mode' => $prorationEnabled ? 'prorated_difference' : $chargeMode,
            'is_upgrade' => $isUpgrade,
            'current_price_cents' => $currentPrice,
            'target_price_cents' => $targetPrice,
            'unused_current_value_cents' => $unusedCurrentValue,
            'remaining_target_value_cents' => $remainingTargetValue,
            'amount_due_cents' => max(0, $amountDue),
            'currency' => $currency,
            'remaining_ratio' => $remainingRatio,
            'summary' => $this->summaryForAmount(max(0, $amountDue), $currency),
        ];
    }

    private function remainingRatio(Subscription $subscription, Carbon $asOf): float
    {
        if (! $subscription->current_period_start || ! $subscription->current_period_end) {
            return 1.0;
        }

        $periodSeconds = max(1, $subscription->current_period_end->getTimestamp() - $subscription->current_period_start->getTimestamp());
        $remainingSeconds = max(0, $subscription->current_period_end->getTimestamp() - $asOf->getTimestamp());

        return max(0.0, min(1.0, $remainingSeconds / $periodSeconds));
    }

    private function amountForChargeMode(
        string $chargeMode,
        int $targetPrice,
        int $currentPrice,
        int $remainingTargetValue,
        int $unusedCurrentValue,
    ): int {
        return match ($chargeMode) {
            'full_target' => $targetPrice,
            'remaining_full_target' => $remainingTargetValue,
            'full_difference' => max(0, $targetPrice - $currentPrice),
            default => max(0, $remainingTargetValue - $unusedCurrentValue),
        };
    }

    private function summaryForAmount(int $amountCents, string $currency): string
    {
        $formatted = number_format($amountCents / 100, 2);

        if ($amountCents <= 0) {
            return 'No immediate payment required. Plan entitlements can be applied immediately.';
        }

        return sprintf('Immediate upgrade charge: %s %s.', $formatted, strtoupper($currency));
    }
}
