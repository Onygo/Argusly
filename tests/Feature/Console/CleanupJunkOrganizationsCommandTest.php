<?php

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reports junk organizations in dry run mode without deleting them', function () {
    $organization = Organization::query()->create([
        'name' => 'Org 03Py',
        'slug' => 'tmp-org-' . Str::lower(Str::random(6)),
        'status' => 'pending',
    ]);

    Workspace::query()->create([
        'name' => 'Junk Workspace',
        'organization_id' => $organization->id,
    ]);

    $user = User::query()->create([
        'name' => 'Junk User',
        'email' => 'junk+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $organization->forceFill([
        'primary_user_id' => $user->id,
    ])->save();

    $this->artisan('organizations:cleanup-junk --dry-run')
        ->expectsOutputToContain('Org 03Py')
        ->expectsOutputToContain('Dry run: 1 junk organization(s) matched, 1 safe candidate(s) would be deleted.')
        ->assertExitCode(0);

    expect(Organization::query()->whereKey($organization->id)->exists())->toBeTrue();
});

it('deletes only safe junk organizations and skips ambiguous matches', function () {
    $safeOrganization = Organization::query()->create([
        'name' => 'Org BxS',
        'slug' => 'dbg-org-' . Str::lower(Str::random(6)),
        'status' => 'pending',
    ]);

    Workspace::query()->create([
        'name' => 'Safe Junk Workspace',
        'organization_id' => $safeOrganization->id,
    ]);

    $safeUser = User::query()->create([
        'name' => 'Safe Junk User',
        'email' => 'safe-junk+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $safeOrganization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $safeOrganization->forceFill([
        'primary_user_id' => $safeUser->id,
    ])->save();

    $unsafeOrganization = Organization::query()->create([
        'name' => 'Org KZqA',
        'slug' => 'tmp-org-' . Str::lower(Str::random(6)),
        'status' => 'pending',
    ]);

    Workspace::query()->create([
        'name' => 'Unsafe Junk Workspace',
        'organization_id' => $unsafeOrganization->id,
    ]);

    $unsafeUser = User::query()->create([
        'name' => 'Unsafe Junk User',
        'email' => 'owner@real-company.test',
        'password' => bcrypt('password'),
        'organization_id' => $unsafeOrganization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $unsafeOrganization->forceFill([
        'primary_user_id' => $unsafeUser->id,
    ])->save();

    $legitimateOrganization = Organization::query()->create([
        'name' => 'Acme Customer',
        'slug' => 'acme-customer',
        'status' => 'active',
    ]);

    $this->artisan('organizations:cleanup-junk')
        ->expectsOutputToContain('Deleted 1 junk organization(s).')
        ->expectsOutputToContain('1 matched organization(s) were skipped because they were not safe to delete automatically.')
        ->assertExitCode(0);

    expect(Organization::query()->whereKey($safeOrganization->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($safeUser->id)->exists())->toBeFalse()
        ->and(Organization::query()->whereKey($unsafeOrganization->id)->exists())->toBeTrue()
        ->and(Organization::query()->whereKey($legitimateOrganization->id)->exists())->toBeTrue();
});
