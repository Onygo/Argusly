<?php

use App\Jobs\SendOnboardingEmailJob;
use App\Mail\OnboardingEmail;
use App\Models\OnboardingState;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('sends an onboarding email only once for the same key', function () {
    Mail::fake();

    $organization = Organization::query()->create([
        'name' => 'Org',
        'slug' => 'org',
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Workspace',
        'organization_id' => $organization->id,
    ]);

    $user = User::query()->create([
        'name' => 'User',
        'email' => 'idempotent@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
    ]);

    OnboardingState::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'phase' => 'verified',
        'registered_at' => now()->subDay(),
        'emails_sent_json' => [],
        'completed_steps_json' => [],
    ]);

    SendOnboardingEmailJob::dispatchSync($user->id, 'welcome');
    SendOnboardingEmailJob::dispatchSync($user->id, 'welcome');

    Mail::assertSent(OnboardingEmail::class, 1);
});
