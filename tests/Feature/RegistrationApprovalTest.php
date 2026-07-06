<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates organization and user on registration request', function () {
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

    $response = $this->post('/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
        'company_name' => 'Acme Inc',
        'plan' => 'starter',
    ]);

    $response->assertRedirect(route('verify-code.show'));
    $this->assertAuthenticated();

    $org = Organization::query()->where('name', 'Acme Inc')->first();
    expect($org)->not->toBeNull();
    expect($org->status)->toBe('pending');

    $user = User::query()->where('email', 'jane@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->organization_id)->toBe($org->id);
    expect($user->active)->toBeTrue();
    expect($user->approved_at)->not->toBeNull();
    expect(trim((string) $user->email_code_hash))->not->toBe('');
    expect($user->email_code_verified_at)->toBeNull();
});

it('silently discards registration when honeypot is filled', function () {
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
        'name' => 'Bot User',
        'email' => 'bot@example.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
        'company_name' => 'Bot Co',
        'company_website' => 'https://example.com',
        'plan' => 'starter',
    ])->assertRedirect(route('login'));

    expect(User::query()->where('email', 'bot@example.com')->exists())->toBeFalse()
        ->and(Organization::query()->where('name', 'Bot Co')->exists())->toBeFalse();
});

it('rejects disposable email domains on registration', function () {
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
        'name' => 'Temp User',
        'email' => 'temp@mailinator.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
        'company_name' => 'Temp Co',
        'plan' => 'starter',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'temp@mailinator.com')->exists())->toBeFalse();
});

it('redirects non approved users away from app', function () {
    $org = Organization::create([
        'name' => 'Pending Org',
        'slug' => 'pending-org',
        'status' => 'pending',
    ]);

    $user = User::create([
        'name' => 'User',
        'email' => 'user@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $org->id,
        'role' => 'owner',
    ]);

    $this->actingAs($user);
    $this->get(route('app.dashboard'))->assertRedirect('/pending');
});

it('inactive users are logged out and redirected to pending activation', function () {
    $org = Organization::create([
        'name' => 'Active Org',
        'slug' => 'active-org-pending-user',
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $user = User::create([
        'name' => 'Inactive Member',
        'email' => 'inactive-member@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $org->id,
        'role' => 'owner',
        'active' => false,
        'approved_at' => now(),
    ]);

    $this->actingAs($user);

    $this->get(route('app.dashboard'))
        ->assertRedirect('/pending')
        ->assertSessionHas('status', 'Account pending activation by admin.');

    $this->assertGuest();
});

it('admin can activate organization and primary user with one action', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
        'approved_at' => now(),
    ]);

    $org = Organization::create([
        'name' => 'Org',
        'slug' => 'org',
        'status' => 'pending',
    ]);

    $user = User::create([
        'name' => 'Member',
        'email' => 'member@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $org->id,
        'role' => 'owner',
        'active' => false,
    ]);
    $org->update(['primary_user_id' => $user->id]);

    $this->actingAs($admin);

    $this->post(route('admin.organizations.activate', $org))->assertRedirect();
    $org->refresh();
    expect($org->status)->toBe('active');
    expect($org->approved_at)->not->toBeNull();
    expect((int) $org->primary_user_id)->toBe((int) $user->id);

    $user->refresh();
    expect($user->approved_at)->not->toBeNull();
    expect($user->active)->toBeTrue();

    $this->actingAs($user);
    $this->get(route('app.dashboard'))->assertRedirect(route('app.onboarding.company.show'));
});

it('approved user can access app dashboard', function () {
    $org = Organization::create([
        'name' => 'Active Org',
        'slug' => 'active-org',
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $user = User::create([
        'name' => 'Member',
        'email' => 'member2@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $org->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $this->actingAs($user);
    $this->get(route('app.dashboard'))->assertRedirect(route('app.onboarding.company.show'));
});

it('on hold organizations are redirected', function () {
    $org = Organization::create([
        'name' => 'Hold Org',
        'slug' => 'hold-org',
        'status' => 'on_hold',
    ]);

    $user = User::create([
        'name' => 'Member',
        'email' => 'member3@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $org->id,
        'role' => 'owner',
        'approved_at' => now(),
    ]);

    $this->actingAs($user);
    $this->get(route('app.dashboard'))->assertRedirect('/on-hold');
});
