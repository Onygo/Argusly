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
        'key' => 'starter',
        'slug' => 'starter',
        'name' => 'Starter',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 2,
        'is_active' => true,
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
