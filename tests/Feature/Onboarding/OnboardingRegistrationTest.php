<?php

use App\Jobs\SendOnboardingEmailJob;
use App\Models\OnboardingState;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates onboarding state on registration', function () {
    Queue::fake();

    Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'platform_250',
        'slug' => 'platform_250',
        'name' => 'Argusly Platform',
        'interval' => 'month',
        'monthly_price_cents' => 9900,
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits' => 250,
        'included_credits_per_interval' => 250,
        'seat_limit' => 5,
        'is_active' => true,
        'is_public' => true,
        'billing_type' => 'fixed',
        'sort_order' => 1,
    ]);

    $this->post('/register', [
        'name' => 'Onboarding User',
        'email' => 'onboarding@example.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
        'company_name' => 'Onboarding Co',
        'plan' => 'starter',
    ])->assertRedirect(route('verify-code.show'));

    $user = User::query()->where('email', 'onboarding@example.com')->first();
    expect($user)->not->toBeNull();

    $state = OnboardingState::query()->where('user_id', $user->id)->first();
    expect($state)->not->toBeNull();
    expect($state->registered_at)->not->toBeNull();

    Queue::assertPushed(SendOnboardingEmailJob::class, function (SendOnboardingEmailJob $job) use ($user) {
        return $job->userId === $user->id && $job->emailKey === 'welcome';
    });
});
