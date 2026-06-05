<?php

use App\Enums\AccessOverrideStatus;
use App\Enums\AccessOverrideType;
use App\Models\AccessOverride;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('expires due access overrides through the artisan command', function () {
    $user = makeAccessOverrideCommandUser();

    $expiredOverride = AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::EARLY_ACCESS,
        'status' => AccessOverrideStatus::ACTIVE,
        'starts_at' => now()->subDays(5),
        'ends_at' => now()->subMinute(),
    ]);

    AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::TRIAL_OVERRIDE,
        'status' => AccessOverrideStatus::ACTIVE,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(3),
    ]);

    $this->artisan('access-overrides:expire')
        ->expectsOutput('Expired 1 access overrides.')
        ->assertExitCode(0);

    expect($expiredOverride->fresh()->status)->toBe(AccessOverrideStatus::EXPIRED)
        ->and($expiredOverride->fresh()->ended_at)->not->toBeNull();
});

function makeAccessOverrideCommandUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Command Org',
        'slug' => 'command-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    Workspace::query()->create([
        'name' => 'Command Workspace',
        'organization_id' => $organization->id,
    ]);

    return User::query()->create([
        'name' => 'Command User',
        'email' => 'command+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
}
