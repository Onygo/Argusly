<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Centralized onboarding fee waiver logic.
 *
 * Provides a single source of truth for onboarding fee waiver state
 * across pricing, checkout, and billing flows.
 */
final class OnboardingFee
{
    /**
     * Check if onboarding fees are currently waived.
     *
     * When true, onboarding fees should be:
     * - Set to 0 in all pricing calculations
     * - Hidden or shown as "waived" in UI
     * - Not charged during checkout
     */
    public static function isWaived(): bool
    {
        return (bool) config('billing.onboarding_fee_waived', false);
    }

    /**
     * Get the effective onboarding fee amount in cents.
     *
     * Returns 0 if waived, otherwise returns the original amount.
     */
    public static function effectiveAmountCents(int $originalAmountCents): int
    {
        if (self::isWaived()) {
            return 0;
        }

        return max(0, $originalAmountCents);
    }

    /**
     * Check if onboarding fee should be charged.
     *
     * Returns false if waived or if the amount is 0.
     */
    public static function shouldCharge(int $originalAmountCents): bool
    {
        if (self::isWaived()) {
            return false;
        }

        return $originalAmountCents > 0;
    }

    /**
     * Check if waived messaging should be shown on public pricing pages.
     *
     * Returns true when fees are waived and there would normally be a fee to show.
     */
    public static function showWaivedMessaging(): bool
    {
        return self::isWaived();
    }
}
