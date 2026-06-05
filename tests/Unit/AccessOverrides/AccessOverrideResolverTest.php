<?php

use App\Domain\AccessOverrides\AccessOverrideResolver;
use App\Enums\AccessOverrideStatus;
use App\Enums\AccessOverrideType;
use App\Models\AccessOverride;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('resolves active scheduled expired and cancelled override states correctly', function () {
    $user = makeAccessOverrideUser();
    $resolver = app(AccessOverrideResolver::class);

    $active = AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::EARLY_ACCESS,
        'status' => AccessOverrideStatus::ACTIVE,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);

    $scheduled = AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::TRIAL_OVERRIDE,
        'status' => AccessOverrideStatus::SCHEDULED,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(5),
    ]);

    $expired = AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::EARLY_ACCESS,
        'status' => AccessOverrideStatus::ACTIVE,
        'starts_at' => now()->subDays(5),
        'ends_at' => now()->subMinute(),
    ]);

    $cancelled = AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::EARLY_ACCESS,
        'status' => AccessOverrideStatus::CANCELLED,
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->addDays(2),
        'ended_at' => now()->subDay(),
    ]);

    expect($resolver->effectiveStatus($active))->toBe(AccessOverrideStatus::ACTIVE)
        ->and($resolver->effectiveStatus($scheduled))->toBe(AccessOverrideStatus::SCHEDULED)
        ->and($resolver->effectiveStatus($expired))->toBe(AccessOverrideStatus::EXPIRED)
        ->and($resolver->effectiveStatus($cancelled))->toBe(AccessOverrideStatus::CANCELLED);
});

it('finds only currently active overrides for a user', function () {
    $user = makeAccessOverrideUser();
    $resolver = app(AccessOverrideResolver::class);

    $scheduled = AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::EARLY_ACCESS,
        'status' => AccessOverrideStatus::SCHEDULED,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addDays(7),
    ]);

    $active = AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::TRIAL_OVERRIDE,
        'status' => AccessOverrideStatus::ACTIVE,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(7),
    ]);

    expect($resolver->getActiveOverrideForUser($user)?->is($active))->toBeTrue()
        ->and($resolver->getActiveOverrideForUser($user)?->is($scheduled))->toBeFalse()
        ->and($resolver->hasActiveOverrideForUser($user))->toBeTrue();
});

function makeAccessOverrideUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Resolver Org',
        'slug' => 'resolver-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    Workspace::query()->create([
        'name' => 'Resolver Workspace',
        'organization_id' => $organization->id,
    ]);

    return User::query()->create([
        'name' => 'Resolver User',
        'email' => 'resolver+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
}
