<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\CreditPack;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('seeds exactly one platform admin user', function () {
    $this->seed(ProductionSeeder::class);
    $this->seed(ProductionSeeder::class);

    expect(User::query()->count())->toBe(1);

    $admin = User::query()->sole();

    expect($admin->email)->toBe(ProductionSeeder::ADMIN_EMAIL)
        ->and(Hash::check(ProductionSeeder::ADMIN_PASSWORD, $admin->password))->toBeTrue()
        ->and($admin->organization_id)->toBeNull()
        ->and($admin->role)->toBe('owner')
        ->and($admin->active)->toBeTrue()
        ->and($admin->is_admin)->toBeTrue()
        ->and($admin->admin_role)->toBe('superadmin')
        ->and($admin->isAdminAreaUser())->toBeTrue()
        ->and($admin->isSuperadmin())->toBeTrue();
});

it('grants the production admin platform permissions', function () {
    $this->seed(ProductionSeeder::class);

    $admin = User::query()->sole();

    expect(Gate::forUser($admin)->allows('admin-area-access'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('admin-area-superadmin'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('manage_llm_settings'))->toBeTrue();
});

it('seeds current pricing plans and credit pricing idempotently', function () {
    $this->seed(ProductionSeeder::class);
    $this->seed(ProductionSeeder::class);

    $plans = Plan::query()->orderBy('sort_order')->pluck('slug')->all();

    expect($plans)->toBe(['platform_250', 'platform_500', 'platform_1000', 'platform_2000', 'enterprise_custom'])
        ->and(Plan::query()->where('slug', 'platform_250')->value('price_monthly_cents'))->toBe(9900)
        ->and(Plan::query()->where('slug', 'platform_500')->value('price_monthly_cents'))->toBe(14900)
        ->and(Plan::query()->where('slug', 'platform_1000')->value('price_monthly_cents'))->toBe(24900)
        ->and(Plan::query()->where('slug', 'platform_2000')->value('price_monthly_cents'))->toBe(39900)
        ->and(Plan::query()->where('slug', 'enterprise_custom')->value('billing_type'))->toBe('custom')
        ->and(CreditPack::query()->where('is_active', true)->count())->toBe(3)
        ->and(SiteSetting::query()->where('key', 'marketing_pricing_page')->exists())->toBeTrue();
});

it('does not seed demo organizations workspaces sites content drafts or users', function () {
    $this->seed(ProductionSeeder::class);

    expect(Organization::query()->count())->toBe(0)
        ->and(Workspace::query()->count())->toBe(0)
        ->and(ClientSite::query()->count())->toBe(0)
        ->and(Content::query()->count())->toBe(0)
        ->and(Draft::query()->count())->toBe(0)
        ->and(User::query()->where('email', '!=', ProductionSeeder::ADMIN_EMAIL)->count())->toBe(0);
});
