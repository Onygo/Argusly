<?php

use App\Jobs\SendOnboardingEmailJob;
use App\Models\OnboardingState;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('queues inactivity onboarding nudges for matching users', function () {
    Bus::fake();

    config()->set('publishlayer.onboarding.require_email_verification', true);

    $organization = Organization::query()->create([
        'name' => 'Org',
        'slug' => 'org-check',
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Workspace',
        'organization_id' => $organization->id,
    ]);

    $makeState = function (string $email, array $overrides = []) use ($organization, $workspace): OnboardingState {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'owner',
            'approved_at' => now(),
        ]);

        return OnboardingState::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'phase' => 'verified',
            'registered_at' => now()->subDays(4),
            'verified_at' => now()->subDays(3),
            'emails_sent_json' => [],
            'completed_steps_json' => [],
        ], $overrides));
    };

    $verifyState = $makeState('verify@example.com', [
        'phase' => 'email_unverified',
        'verified_at' => null,
        'registered_at' => now()->subDays(4),
    ]);

    $nudgeLoginState = $makeState('login@example.com', [
        'phase' => 'verified',
        'verified_at' => now()->subHours(50),
        'first_login_at' => null,
    ]);

    $nudgeNoActionState = $makeState('noaction@example.com', [
        'phase' => 'first_login',
        'first_login_at' => now()->subDays(4),
        'first_value_at' => null,
    ]);

    $reengageState = $makeState('reengage@example.com', [
        'phase' => 'activated',
        'first_value_at' => now()->subDays(30),
        'last_activity_at' => now()->subDays(15),
    ]);

    $this->artisan('onboarding:check-inactivity --limit=100')
        ->assertSuccessful();

    Bus::assertDispatched(SendOnboardingEmailJob::class, fn (SendOnboardingEmailJob $job) => $job->userId === $verifyState->user_id && $job->emailKey === 'verify_reminder_1');
    Bus::assertDispatched(SendOnboardingEmailJob::class, fn (SendOnboardingEmailJob $job) => $job->userId === $verifyState->user_id && $job->emailKey === 'verify_reminder_2');
    Bus::assertDispatched(SendOnboardingEmailJob::class, fn (SendOnboardingEmailJob $job) => $job->userId === $nudgeLoginState->user_id && $job->emailKey === 'nudge_login');
    Bus::assertDispatched(SendOnboardingEmailJob::class, fn (SendOnboardingEmailJob $job) => $job->userId === $nudgeNoActionState->user_id && $job->emailKey === 'nudge_no_action');
    Bus::assertDispatched(SendOnboardingEmailJob::class, fn (SendOnboardingEmailJob $job) => $job->userId === $reengageState->user_id && $job->emailKey === 'reengage');
});

