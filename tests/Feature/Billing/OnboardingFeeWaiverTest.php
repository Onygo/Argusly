<?php

use App\Support\OnboardingFee;

describe('OnboardingFee support class', function () {
    it('returns false for isWaived when config is disabled', function () {
        config(['billing.onboarding_fee_waived' => false]);

        expect(OnboardingFee::isWaived())->toBeFalse();
    });

    it('returns true for isWaived when config is enabled', function () {
        config(['billing.onboarding_fee_waived' => true]);

        expect(OnboardingFee::isWaived())->toBeTrue();
    });

    it('returns original amount when not waived', function () {
        config(['billing.onboarding_fee_waived' => false]);

        expect(OnboardingFee::effectiveAmountCents(25000))->toBe(25000);
    });

    it('returns zero when waived', function () {
        config(['billing.onboarding_fee_waived' => true]);

        expect(OnboardingFee::effectiveAmountCents(25000))->toBe(0);
    });

    it('returns true for shouldCharge when not waived and amount is positive', function () {
        config(['billing.onboarding_fee_waived' => false]);

        expect(OnboardingFee::shouldCharge(25000))->toBeTrue();
    });

    it('returns false for shouldCharge when waived', function () {
        config(['billing.onboarding_fee_waived' => true]);

        expect(OnboardingFee::shouldCharge(25000))->toBeFalse();
    });

    it('returns false for shouldCharge when amount is zero', function () {
        config(['billing.onboarding_fee_waived' => false]);

        expect(OnboardingFee::shouldCharge(0))->toBeFalse();
    });

    it('returns true for showWaivedMessaging when waived', function () {
        config(['billing.onboarding_fee_waived' => true]);

        expect(OnboardingFee::showWaivedMessaging())->toBeTrue();
    });

    it('returns false for showWaivedMessaging when not waived', function () {
        config(['billing.onboarding_fee_waived' => false]);

        expect(OnboardingFee::showWaivedMessaging())->toBeFalse();
    });
});
