<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows a platform admin to grant early bird access', function () {
    [$organization, $platformAdmin] = earlyBirdContext();

    $this->actingAs($platformAdmin)
        ->post(route('admin.organizations.access.grant-early-bird', $organization), [
            'early_bird_ends_at' => now()->addDays(14)->toDateString(),
            'early_bird_note' => 'Commercial pilot access.',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Early Bird access granted.');

    $organization->refresh();

    expect((string) $organization->access_tier)->toBe(Organization::ACCESS_TIER_EARLY_BIRD)
        ->and($organization->early_bird_started_at)->not->toBeNull()
        ->and($organization->early_bird_ends_at?->isFuture())->toBeTrue()
        ->and((string) $organization->early_bird_note)->toBe('Commercial pilot access.');
});

it('blocks a regular organization user from managing early bird access', function () {
    [$organization, , $orgOwner] = earlyBirdContext();

    $this->actingAs($orgOwner)
        ->post(route('admin.organizations.access.grant-early-bird', $organization), [
            'early_bird_ends_at' => now()->addDays(7)->toDateString(),
        ])
        ->assertForbidden();
});

it('allows app access without a subscription while early bird is active', function () {
    [$organization, $platformAdmin, $orgOwner] = earlyBirdContext();

    $this->actingAs($platformAdmin)
        ->post(route('admin.organizations.access.grant-early-bird', $organization), [
            'early_bird_ends_at' => now()->addDays(10)->toDateString(),
            'early_bird_note' => 'Pilot',
        ]);

    $workspace = Workspace::query()->where('organization_id', $organization->id)->firstOrFail();

    $this->actingAs($orgOwner)
        ->post('/app/sites', [
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Early Bird Site',
            'site_url' => 'https://early-bird.example.com',
        ])
        ->assertRedirect();
});

it('shows when early bird access is expired', function () {
    [$organization, $platformAdmin] = earlyBirdContext();

    $organization->update([
        'access_tier' => Organization::ACCESS_TIER_EARLY_BIRD,
        'early_bird_started_at' => now()->subDays(20),
        'early_bird_ends_at' => now()->subDay(),
        'access_updated_by' => $platformAdmin->id,
    ]);

    $this->actingAs($platformAdmin)
        ->get(route('admin.organizations.show', $organization))
        ->assertOk()
        ->assertSee('Early Bird expired');
});

it('allows early bird access to be extended', function () {
    [$organization, $platformAdmin] = earlyBirdContext();

    $organization->update([
        'access_tier' => Organization::ACCESS_TIER_EARLY_BIRD,
        'early_bird_started_at' => now()->subDays(5),
        'early_bird_ends_at' => now()->addDay(),
    ]);

    $this->actingAs($platformAdmin)
        ->post(route('admin.organizations.access.extend-early-bird', $organization), [
            'early_bird_ends_at' => now()->addDays(30)->toDateString(),
            'early_bird_note' => 'Extended after onboarding.',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Early Bird access updated.');

    expect($organization->fresh()->early_bird_ends_at?->isAfter(now()->addDays(25)))->toBeTrue();
});

it('allows early bird access to be converted to paid', function () {
    [$organization, $platformAdmin] = earlyBirdContext();

    $organization->update([
        'access_tier' => Organization::ACCESS_TIER_EARLY_BIRD,
        'early_bird_started_at' => now()->subDays(5),
        'early_bird_ends_at' => now()->addDays(5),
    ]);

    $this->actingAs($platformAdmin)
        ->post(route('admin.organizations.access.convert-to-paid', $organization))
        ->assertRedirect()
        ->assertSessionHas('status', 'Organization converted to paid access.');

    $organization->refresh();

    expect((string) $organization->access_tier)->toBe(Organization::ACCESS_TIER_PAID)
        ->and($organization->converted_to_paid_at)->not->toBeNull();
});

it('writes an audit log entry for early bird changes', function () {
    [$organization, $platformAdmin] = earlyBirdContext();

    $this->actingAs($platformAdmin)
        ->post(route('admin.organizations.access.grant-early-bird', $organization), [
            'early_bird_ends_at' => now()->addDays(10)->toDateString(),
            'early_bird_note' => 'Audit me',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('audit_logs', [
        'subject_type' => Organization::class,
        'subject_id' => (string) $organization->id,
        'action' => 'organization.access.early_bird_granted',
    ]);

    expect(AuditLog::query()->where('action', 'organization.access.early_bird_granted')->exists())->toBeTrue();
});

function earlyBirdContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Early Bird Org',
        'slug' => 'early-bird-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    Workspace::query()->create([
        'name' => 'Early Bird Workspace',
        'display_name' => 'Early Bird Workspace',
        'organization_id' => $organization->id,
    ]);

    $platformAdmin = User::query()->create([
        'name' => 'Platform Admin',
        'email' => 'platform-admin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);

    $orgOwner = User::query()->create([
        'name' => 'Org Owner',
        'email' => 'org-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $organization->update([
        'primary_user_id' => $orgOwner->id,
    ]);

    return [$organization, $platformAdmin, $orgOwner];
}
