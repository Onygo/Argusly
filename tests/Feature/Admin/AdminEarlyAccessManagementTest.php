<?php

use App\Enums\EarlyAccessSignupStatus;
use App\Mail\EarlyAccessInvitationMail;
use App\Models\AccessOverride;
use App\Models\EarlyAccessInvite;
use App\Models\EarlyAccessPilotCost;
use App\Models\EarlyAccessSignup;
use App\Models\LlmRequest;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows admin access to the early access list and blocks non admins', function () {
    $admin = makeEarlyAccessAdmin('admin');
    $user = makeEarlyAccessRegularUser();

    $signup = EarlyAccessSignup::query()->create([
        'full_name' => 'Access Candidate',
        'email' => 'candidate@example.com',
        'status' => EarlyAccessSignupStatus::NEW,
        'submitted_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.early-access.index'))
        ->assertOk()
        ->assertSee($signup->email);

    $this->actingAs($user)
        ->get(route('admin.early-access.index'))
        ->assertStatus(403);
});

it('supports review approve reject and note updates', function () {
    $admin = makeEarlyAccessAdmin('admin');

    $signup = EarlyAccessSignup::query()->create([
        'full_name' => 'Status Candidate',
        'email' => 'status@example.com',
        'status' => EarlyAccessSignupStatus::NEW,
        'submitted_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.early-access.review', $signup))
        ->assertRedirect();

    $signup->refresh();
    expect($signup->status)->toBe(EarlyAccessSignupStatus::REVIEWED)
        ->and($signup->reviewed_at)->not->toBeNull();

    $this->actingAs($admin)
        ->post(route('admin.early-access.approve', $signup))
        ->assertRedirect();

    $signup->refresh();
    expect($signup->status)->toBe(EarlyAccessSignupStatus::APPROVED)
        ->and($signup->approved_at)->not->toBeNull();

    $this->actingAs($admin)
        ->post(route('admin.early-access.notes.update', $signup), [
            'internal_notes' => 'Strong fit for the current rollout.',
        ])
        ->assertRedirect();

    $signup->refresh();
    expect((string) $signup->internal_notes)->toContain('Strong fit');

    $this->actingAs($admin)
        ->post(route('admin.early-access.reject', $signup))
        ->assertRedirect();

    $signup->refresh();
    expect($signup->status)->toBe(EarlyAccessSignupStatus::REJECTED)
        ->and($signup->rejected_at)->not->toBeNull();
});

it('sends and resends early access invites from the admin flow', function () {
    Mail::fake();

    $admin = makeEarlyAccessAdmin('admin');
    $signup = makeApprovedEarlyAccessSignup();

    $this->actingAs($admin)
        ->post(route('admin.early-access.send-invite', $signup))
        ->assertRedirect();

    $signup->refresh();
    $firstInvite = EarlyAccessInvite::query()->where('early_access_signup_id', $signup->id)->latest('created_at')->first();

    expect($signup->status)->toBe(EarlyAccessSignupStatus::INVITED)
        ->and($signup->invited_at)->not->toBeNull()
        ->and($firstInvite)->not->toBeNull()
        ->and((string) $firstInvite?->token)->not->toStartWith('s:');

    Mail::assertSent(EarlyAccessInvitationMail::class, 1);

    $this->actingAs($admin)
        ->post(route('admin.early-access.resend-invite', $signup))
        ->assertRedirect();

    $invites = EarlyAccessInvite::query()
        ->where('early_access_signup_id', $signup->id)
        ->orderBy('created_at')
        ->get();

    expect($invites)->toHaveCount(2)
        ->and($invites->first()->expires_at)->not->toBeNull()
        ->and($invites->last()->accepted_at)->toBeNull();

    Mail::assertSent(EarlyAccessInvitationMail::class, 2);
});

it('lets admins create and invite a pilot application manually', function () {
    Mail::fake();

    $admin = makeEarlyAccessAdmin('admin');

    $this->actingAs($admin)
        ->post(route('admin.early-access.invite-pilot-user'), [
            'full_name' => 'Manual Pilot',
            'email' => 'manual-pilot@example.com',
            'company_name' => 'Manual Co',
            'website' => 'https://manual.example',
            'notes' => 'Invite directly from a sales conversation.',
        ])
        ->assertRedirect();

    $signup = EarlyAccessSignup::query()->where('email', 'manual-pilot@example.com')->first();

    expect($signup)->not->toBeNull()
        ->and($signup->status)->toBe(EarlyAccessSignupStatus::INVITED)
        ->and($signup->source)->toBe('admin_invite')
        ->and($signup->priority)->toBe('high')
        ->and($signup->assigned_admin_id)->toBe($admin->id)
        ->and($signup->qualification_score)->toBeGreaterThan(0);

    expect(EarlyAccessInvite::query()->where('early_access_signup_id', $signup->id)->exists())->toBeTrue();
    Mail::assertSent(EarlyAccessInvitationMail::class);
});

it('shows pilot program qualification scores in the admin list', function () {
    $admin = makeEarlyAccessAdmin('admin');

    EarlyAccessSignup::query()->create([
        'full_name' => 'Scored Candidate',
        'email' => 'scored@example.com',
        'company_name' => 'Scored Co',
        'website' => 'https://scored.example',
        'status' => EarlyAccessSignupStatus::NEW,
        'qualification_score' => 82,
        'submitted_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.early-access.index'))
        ->assertOk()
        ->assertSee('Pilot Program')
        ->assertSee('82/100')
        ->assertSee('Hot');
});

it('does not allow invites for rejected signups', function () {
    Mail::fake();

    $admin = makeEarlyAccessAdmin('admin');
    $signup = EarlyAccessSignup::query()->create([
        'full_name' => 'Rejected Candidate',
        'email' => 'rejected@example.com',
        'status' => EarlyAccessSignupStatus::REJECTED,
        'submitted_at' => now(),
        'rejected_at' => now(),
    ]);

    $this->actingAs($admin)
        ->from(route('admin.early-access.show', $signup))
        ->post(route('admin.early-access.send-invite', $signup))
        ->assertRedirect(route('admin.early-access.show', $signup))
        ->assertSessionHasErrors('early_access');

    expect(EarlyAccessInvite::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('tracks manual and ai pilot costs for an early access signup', function () {
    $admin = makeEarlyAccessAdmin('admin');
    $organization = Organization::query()->create([
        'name' => 'Pilot Org',
        'slug' => 'pilot-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'name' => 'Pilot Workspace',
        'display_name' => 'Pilot Workspace',
        'organization_id' => $organization->id,
    ]);
    $signup = makeApprovedEarlyAccessSignup([
        'status' => EarlyAccessSignupStatus::ACTIVATED,
        'activated_at' => now(),
        'workspace_id' => $workspace->id,
    ]);

    LlmRequest::query()->create([
        'workspace_id' => $workspace->id,
        'feature' => 'draft.generate',
        'provider' => 'openai',
        'model' => 'gpt-test',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'total_tokens' => 1500,
        'credits_consumed' => 4,
        'input_cost_eur' => 1.25,
        'output_cost_eur' => 2.50,
        'total_cost_eur' => 3.75,
        'status' => 'success',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.early-access.pilot-costs.store', $signup), [
            'category' => EarlyAccessPilotCost::CATEGORY_ONBOARDING,
            'description' => 'Kickoff and setup session',
            'amount_eur' => '125.50',
            'incurred_on' => now()->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('early_access_pilot_costs', [
        'early_access_signup_id' => $signup->id,
        'category' => EarlyAccessPilotCost::CATEGORY_ONBOARDING,
        'description' => 'Kickoff and setup session',
        'amount_cents' => 12550,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.early-access.show', $signup))
        ->assertOk()
        ->assertSee('€129.25')
        ->assertSee('€3.75')
        ->assertSee('€125.50')
        ->assertSee('Kickoff and setup session');
});

it('removes manual pilot costs only from their own signup', function () {
    $admin = makeEarlyAccessAdmin('admin');
    $signup = makeApprovedEarlyAccessSignup();
    $otherSignup = makeApprovedEarlyAccessSignup();

    $cost = EarlyAccessPilotCost::query()->create([
        'early_access_signup_id' => $signup->id,
        'category' => EarlyAccessPilotCost::CATEGORY_SUPPORT,
        'description' => 'Support review',
        'amount_cents' => 2500,
        'currency' => 'EUR',
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.early-access.pilot-costs.destroy', [$otherSignup, $cost]))
        ->assertNotFound();

    $this->assertDatabaseHas('early_access_pilot_costs', [
        'id' => $cost->id,
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.early-access.pilot-costs.destroy', [$signup, $cost]))
        ->assertRedirect();

    $this->assertDatabaseMissing('early_access_pilot_costs', [
        'id' => $cost->id,
    ]);
});

it('blocks invite creation when the signup email already belongs to an active organization user', function () {
    Mail::fake();

    $admin = makeEarlyAccessAdmin('admin');
    $existingOrganization = Organization::query()->create([
        'name' => 'Existing Org',
        'slug' => 'existing-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    User::query()->create([
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $existingOrganization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $signup = EarlyAccessSignup::query()->create([
        'full_name' => 'Existing User',
        'email' => 'existing@example.com',
        'status' => EarlyAccessSignupStatus::APPROVED,
        'submitted_at' => now(),
        'approved_at' => now(),
    ]);

    $this->actingAs($admin)
        ->from(route('admin.early-access.show', $signup))
        ->post(route('admin.early-access.send-invite', $signup))
        ->assertRedirect(route('admin.early-access.show', $signup))
        ->assertSessionHasErrors('early_access');

    expect(EarlyAccessInvite::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('adds an existing organization user to the pilot program without sending an invite', function (): void {
    Mail::fake();

    $admin = makeEarlyAccessAdmin('admin');
    $user = makeEarlyAccessRegularUser();
    $workspace = Workspace::query()->create([
        'organization_id' => $user->organization_id,
        'name' => 'Existing Pilot Workspace',
        'display_name' => 'Existing Pilot Workspace',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.early-access.add-existing-user'), [
            'email' => $user->email,
            'workspace_id' => $workspace->id,
            'notes' => 'Add this customer to pilot tracking.',
        ])
        ->assertRedirect();

    $signup = EarlyAccessSignup::query()->where('email', $user->email)->first();

    expect($signup)->not->toBeNull()
        ->and($signup->status)->toBe(EarlyAccessSignupStatus::ACTIVATED)
        ->and($signup->activated_user_id)->toBe($user->id)
        ->and((string) $signup->workspace_id)->toBe((string) $workspace->id)
        ->and(AccessOverride::query()->where('user_id', $user->id)->where('type', 'early_access')->open()->exists())->toBeTrue()
        ->and(Subscription::query()->where('organization_id', $user->organization_id)->where('status_reason', 'early_access_existing_user')->exists())->toBeTrue();

    Mail::assertNothingSent();
});

it('does not replace an existing active subscription when adding an existing user to pilot', function (): void {
    $admin = makeEarlyAccessAdmin('admin');
    $user = makeEarlyAccessRegularUser();
    $workspace = Workspace::query()->create([
        'organization_id' => $user->organization_id,
        'name' => 'Paid Customer Workspace',
        'display_name' => 'Paid Customer Workspace',
    ]);
    $plan = Plan::query()->create([
        'key' => 'paid-test',
        'slug' => 'paid-test',
        'name' => 'Paid Test',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 300,
        'included_credits_per_interval' => 300,
        'seat_limit' => 3,
        'limits' => ['users' => 3, 'sites' => 1, 'workspaces' => 1],
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'organization_id' => $user->organization_id,
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 300,
        'seat_limit' => 3,
        'status' => 'active',
        'status_reason' => 'paid',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->endOfDay(),
        'provider' => 'manual',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.early-access.add-existing-user'), [
            'email' => $user->email,
            'workspace_id' => $workspace->id,
        ])
        ->assertRedirect();

    expect(Subscription::query()->where('organization_id', $user->organization_id)->count())->toBe(1)
        ->and($subscription->refresh()->status_reason)->toBe('paid')
        ->and(EarlyAccessSignup::query()->where('email', $user->email)->where('status', EarlyAccessSignupStatus::ACTIVATED)->exists())->toBeTrue();
});

function makeEarlyAccessAdmin(string $role): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => ucfirst($role) . ' Admin',
        'email' => $role . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => $role,
    ]);
}

function makeEarlyAccessRegularUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Regular Org',
        'slug' => 'regular-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Regular User',
        'email' => 'regular+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);
}

function makeApprovedEarlyAccessSignup(array $overrides = []): EarlyAccessSignup
{
    return EarlyAccessSignup::query()->create(array_merge([
        'full_name' => 'Approved Candidate',
        'email' => 'approved+' . Str::lower(Str::random(6)) . '@example.com',
        'company_name' => 'Approved Co',
        'status' => EarlyAccessSignupStatus::APPROVED,
        'submitted_at' => now(),
        'reviewed_at' => now(),
        'approved_at' => now(),
    ], $overrides));
}
