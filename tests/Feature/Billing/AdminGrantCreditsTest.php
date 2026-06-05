<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('admin can grant free credits to an organization site wallet', function () {
    $organization = Organization::create([
        'name' => 'Grant Org',
        'slug' => 'grant-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Grant Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Grant Site',
        'site_url' => 'https://grant.example.com',
        'allowed_domains' => ['grant.example.com'],
        'is_active' => true,
    ]);

    $admin = User::create([
        'name' => 'Platform Admin',
        'email' => 'admin+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.organizations.billing.grant-credits', $organization), [
            'client_site_id' => (string) $site->id,
            'amount' => 120,
            'note' => 'Manual gift',
        ])
        ->assertRedirect();

    $summary = app(CreditWalletService::class)->getSummary((string) $site->id);
    expect((int) $summary['available'])->toBe(120);
});

