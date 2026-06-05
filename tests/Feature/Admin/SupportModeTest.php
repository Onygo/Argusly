<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('superadmin can start and stop support mode', function () {
    [$superadmin, $targetOrg, $targetUser] = makeSupportFixture();

    $this->actingAs($superadmin)
        ->post(route('admin.support.start'), [
            'company_id' => $targetOrg->id,
            'user_id' => $targetUser->id,
            'reason' => 'Investigate brief visibility',
        ])
        ->assertRedirect(route('admin.support.index'));

    expect(session('support_mode_enabled'))->toBeTrue();
    expect((int) session('support_target_company_id'))->toBe((int) $targetOrg->id);
    expect((int) session('support_target_user_id'))->toBe((int) $targetUser->id);

    $this->actingAs($superadmin)
        ->post(route('admin.support.stop'))
        ->assertRedirect(route('admin.support.index'));

    expect(session('support_mode_enabled'))->toBeNull();
});

it('non superadmin cannot start support mode', function () {
    [$superadmin, $targetOrg, $targetUser] = makeSupportFixture();

    $admin = User::query()->create([
        'name' => 'Scoped Admin',
        'email' => 'scoped-admin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $superadmin->organization_id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.support.start'), [
            'company_id' => $targetOrg->id,
            'user_id' => $targetUser->id,
        ])
        ->assertStatus(403);
});

it('support mode does not change authenticated user', function () {
    [$superadmin, $targetOrg, $targetUser] = makeSupportFixture();

    $this->actingAs($superadmin)
        ->post(route('admin.support.start'), [
            'company_id' => $targetOrg->id,
            'user_id' => $targetUser->id,
        ])
        ->assertRedirect();

    $this->actingAs($superadmin)
        ->get(route('app.dashboard'))
        ->assertOk();

    expect(auth()->id())->toBe($superadmin->id);
});

it('blocks write requests in support mode', function () {
    [$superadmin, $targetOrg, $targetUser] = makeSupportFixture();

    $this->actingAs($superadmin)
        ->post(route('admin.support.start'), [
            'company_id' => $targetOrg->id,
            'user_id' => $targetUser->id,
        ])
        ->assertRedirect();

    $this->actingAs($superadmin)
        ->post(route('app.settings.notifications'), [
            'brief_updates' => 1,
            'draft_ready' => 1,
            'weekly_summary' => 1,
        ])
        ->assertStatus(403);
});

it('constrains app read scope to the support target organization', function () {
    [$superadmin, $targetOrg, $targetUser, $adminOrg] = makeSupportFixture();

    Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Admin Workspace',
        'display_name' => 'Admin Workspace',
        'organization_id' => $adminOrg->id,
    ]);

    Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Target Workspace',
        'display_name' => 'Target Workspace',
        'organization_id' => $targetOrg->id,
    ]);

    $this->actingAs($superadmin)
        ->post(route('admin.support.start'), [
            'company_id' => $targetOrg->id,
            'user_id' => $targetUser->id,
        ])
        ->assertRedirect();

    $this->actingAs($superadmin)
        ->get(route('app.settings'))
        ->assertOk()
        ->assertSee('Workspace: Target Workspace')
        ->assertDontSee('Workspace: Admin Workspace');
});

it('redacts secrets from diagnostics and snapshot outputs', function () {
    [$superadmin, $targetOrg, $targetUser] = makeSupportFixture();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException api_key=sk-secret token=abc123',
        'failed_at' => now(),
    ]);

    $this->actingAs($superadmin)
        ->post(route('admin.support.start'), [
            'company_id' => $targetOrg->id,
            'user_id' => $targetUser->id,
        ])
        ->assertRedirect();

    $diagnostics = $this->actingAs($superadmin)
        ->get(route('admin.support.diagnostics'))
        ->assertOk()
        ->json();

    expect(json_encode($diagnostics))->not->toContain('sk-secret')
        ->and(json_encode($diagnostics))->not->toContain('abc123');

    $response = $this->actingAs($superadmin)
        ->get(route('admin.support.snapshot'))
        ->assertOk();

    $file = $response->baseResponse->getFile();
    expect($file)->not->toBeNull();
    $json = file_get_contents($file->getPathname());
    expect($json)->not->toContain('sk-secret')
        ->and($json)->not->toContain('abc123')
        ->and($json)->toContain('[REDACTED]');
});

it('cleans up expired support snapshots', function () {
    Storage::disk('local')->put('support/old.json', '{"ok":true}');
    $oldPath = Storage::disk('local')->path('support/old.json');
    touch($oldPath, now()->subDays(10)->timestamp);

    $exit = Artisan::call('support:cleanup-snapshots --days=7');
    expect($exit)->toBe(0);
    expect(Storage::disk('local')->exists('support/old.json'))->toBeFalse();
});

function makeSupportFixture(): array
{
    $adminOrg = Organization::query()->create([
        'name' => 'Admin Org',
        'slug' => 'admin-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Admin Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $targetOrg = Organization::query()->create([
        'name' => 'Target Org',
        'slug' => 'target-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Target Org BV',
        'billing_address_line1' => 'Teststraat 456',
        'billing_country_code' => 'NL',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $adminOrg->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $targetOrg->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $superadmin = User::query()->create([
        'name' => 'Support Superadmin',
        'email' => 'support-superadmin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $adminOrg->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'superadmin',
    ]);

    $targetUser = User::query()->create([
        'name' => 'Target User',
        'email' => 'target-user+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $targetOrg->id,
        'role' => 'editor',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
        'admin_role' => 'user',
    ]);

    return [$superadmin, $targetOrg, $targetUser, $adminOrg];
}
