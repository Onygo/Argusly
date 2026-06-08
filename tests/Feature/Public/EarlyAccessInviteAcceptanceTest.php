<?php

use App\Enums\EarlyAccessSignupStatus;
use App\Models\EarlyAccessInvite;
use App\Models\EarlyAccessSignup;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('accepts an early access invite and provisions the account workspace and plan', function () {
    $signup = EarlyAccessSignup::query()->create([
        'full_name' => 'Activation Candidate',
        'email' => 'activate@example.com',
        'company_name' => 'Activation Co',
        'status' => EarlyAccessSignupStatus::INVITED,
        'submitted_at' => now(),
        'reviewed_at' => now(),
        'approved_at' => now(),
        'invited_at' => now(),
    ]);

    $plainToken = 'early-access-token-' . Str::random(24);

    $invite = EarlyAccessInvite::query()->create([
        'early_access_signup_id' => $signup->id,
        'email' => $signup->email,
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'expires_at' => now()->addDays(14),
    ]);

    $this->get(route('public.early-access.invites.show', $plainToken))
        ->assertOk()
        ->assertSee('Activate Pilot Program access');

    $this->post(route('public.early-access.invites.store', $plainToken), [
        'name' => 'Activation Candidate',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
    ])->assertRedirect(route('login'));

    $invite->refresh();
    $signup->refresh();

    $user = User::query()->where('email', 'activate@example.com')->first();
    $workspace = Workspace::query()->find($signup->workspace_id);
    $organization = $workspace?->organization;
    $subscription = Subscription::query()
        ->where('organization_id', $organization?->id)
        ->latest('created_at')
        ->first();
    $plan = $subscription?->plan;

    expect($invite->accepted_at)->not->toBeNull()
        ->and($signup->status)->toBe(EarlyAccessSignupStatus::ACTIVATED)
        ->and($signup->activated_at)->not->toBeNull()
        ->and($user)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and((int) $user->organization_id)->toBe((int) $organization?->id)
        ->and($organization)->not->toBeNull()
        ->and($organization->status)->toBe('active')
        ->and($workspace)->not->toBeNull()
        ->and($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe('active')
        ->and($plan)->toBeInstanceOf(Plan::class)
        ->and((string) $plan->key)->toBe('early_access')
        ->and((string) $organization->active_subscription_id)->toBe((string) $subscription?->id)
        ->and(WorkspaceEntitlement::query()->where('workspace_id', $workspace?->id)->count())->toBeGreaterThan(0);

    $this->post(route('public.early-access.invites.store', $plainToken), [
        'name' => 'Activation Candidate',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
    ])->assertNotFound();
});

it('returns gone for expired early access invites', function () {
    $signup = EarlyAccessSignup::query()->create([
        'full_name' => 'Expired Candidate',
        'email' => 'expired@example.com',
        'status' => EarlyAccessSignupStatus::INVITED,
        'submitted_at' => now(),
        'approved_at' => now(),
        'invited_at' => now(),
    ]);

    $plainToken = 'expired-token-' . Str::random(24);

    EarlyAccessInvite::query()->create([
        'early_access_signup_id' => $signup->id,
        'email' => $signup->email,
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'expires_at' => now()->subMinute(),
    ]);

    $this->get(route('public.early-access.invites.show', $plainToken))
        ->assertStatus(410);
});

it('handles existing user conflicts safely when an invite is accepted', function () {
    $organization = Organization::query()->create([
        'name' => 'Occupied Org',
        'slug' => 'occupied-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    User::query()->create([
        'name' => 'Existing Member',
        'email' => 'occupied@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $signup = EarlyAccessSignup::query()->create([
        'full_name' => 'Occupied Candidate',
        'email' => 'occupied@example.com',
        'status' => EarlyAccessSignupStatus::INVITED,
        'submitted_at' => now(),
        'approved_at' => now(),
        'invited_at' => now(),
    ]);

    $plainToken = 'occupied-token-' . Str::random(24);

    EarlyAccessInvite::query()->create([
        'early_access_signup_id' => $signup->id,
        'email' => $signup->email,
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'expires_at' => now()->addDays(14),
    ]);

    $this->from(route('public.early-access.invites.show', $plainToken))
        ->post(route('public.early-access.invites.store', $plainToken), [
            'name' => 'Occupied Candidate',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])
        ->assertRedirect(route('public.early-access.invites.show', $plainToken))
        ->assertSessionHasErrors('invite');

    $signup->refresh();

    expect($signup->status)->toBe(EarlyAccessSignupStatus::INVITED)
        ->and($signup->activated_at)->toBeNull();
});
