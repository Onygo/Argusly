<?php

use App\Jobs\SendOnboardingEmailJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\OnboardingState;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('triggers first value email only once', function () {
    Bus::fake();

    $organization = Organization::query()->create([
        'name' => 'Org',
        'slug' => 'org-fv',
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Workspace',
        'organization_id' => $organization->id,
    ]);

    $user = User::query()->create([
        'name' => 'User',
        'email' => 'first-value@example.com',
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
        'phase' => 'first_login',
        'registered_at' => now()->subDays(2),
        'first_login_at' => now()->subDay(),
        'emails_sent_json' => [],
        'completed_steps_json' => [],
    ]);

    $site = ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => [],
        'is_active' => true,
        'status' => 'connected',
    ]);

    Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'status' => 'queued',
        'title' => 'First brief',
        'language' => 'en',
        'intent' => 'seo',
        'primary_keyword' => 'keyword',
        'audience' => 'marketers',
        'output_type' => 'article',
    ]);

    Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'status' => 'queued',
        'title' => 'Second brief',
        'language' => 'en',
        'intent' => 'seo',
        'primary_keyword' => 'keyword-2',
        'audience' => 'marketers',
        'output_type' => 'article',
    ]);

    $state = OnboardingState::query()->where('user_id', $user->id)->first();
    expect($state->phase)->toBe('activated');
    expect($state->first_value_at)->not->toBeNull();

    Bus::assertDispatchedTimes(SendOnboardingEmailJob::class, 1);
    Bus::assertDispatched(SendOnboardingEmailJob::class, function (SendOnboardingEmailJob $job) use ($user) {
        return $job->userId === $user->id && $job->emailKey === 'first_value_ready';
    });
});
