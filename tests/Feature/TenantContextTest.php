<?php

namespace Tests\Feature;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\BelongsToBrand;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_account_resolves_first_accessible_account_and_persists_context(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Argusly', 'slug' => 'argusly']);

        $user->accounts()->attach($account, ['status' => 'active']);

        $this->actingAs($user);

        $currentAccount = app(CurrentAccountContract::class)->get($user);

        $this->assertTrue($account->is($currentAccount));
        $this->assertSame($account->id, session('tenant.current_account_id'));
        $this->assertSame($account->id, Cache::get("tenant-context:user:{$user->id}:account"));
    }

    public function test_account_switch_rejects_accounts_the_user_cannot_access(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Other', 'slug' => 'other']);

        $this->actingAs($user);

        $this->expectException(AccessDeniedHttpException::class);

        app(CurrentAccountContract::class)->switch($account, $user);
    }

    public function test_account_switch_rejects_inactive_accounts_even_with_membership(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Inactive', 'slug' => 'inactive', 'status' => 'inactive']);

        $user->accounts()->attach($account, ['status' => 'active']);

        $this->actingAs($user);

        $this->expectException(AccessDeniedHttpException::class);

        app(CurrentAccountContract::class)->switch($account, $user);
    }

    public function test_account_switch_clears_current_brand_context(): void
    {
        $user = User::factory()->create();
        $firstAccount = Account::query()->create(['name' => 'First', 'slug' => 'first']);
        $secondAccount = Account::query()->create(['name' => 'Second', 'slug' => 'second']);
        $brand = Brand::query()->create(['account_id' => $firstAccount->id, 'name' => 'Kia', 'slug' => 'kia']);

        $user->accounts()->attach($firstAccount, ['status' => 'active']);
        $user->accounts()->attach($secondAccount, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $firstAccount->id, 'status' => 'active']);

        $this->actingAs($user);

        app(CurrentAccountContract::class)->set($firstAccount, $user);
        app(CurrentBrandContract::class)->set($brand, $user);

        app(CurrentAccountContract::class)->switch($secondAccount, $user);

        $this->assertSame($secondAccount->id, session('tenant.current_account_id'));
        $this->assertNull(session('tenant.current_brand_id'));
        $this->assertNull(Cache::get("tenant-context:user:{$user->id}:brand"));
    }

    public function test_brand_context_requires_brand_and_account_membership(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account', 'slug' => 'account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Kia', 'slug' => 'kia']);

        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        $this->actingAs($user);

        $this->expectException(AccessDeniedHttpException::class);

        app(CurrentBrandContract::class)->set($brand, $user);
    }

    public function test_brand_switch_rejects_brands_the_user_is_not_assigned_to(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account', 'slug' => 'account']);
        $assignedBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Assigned', 'slug' => 'assigned']);
        $unassignedBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Unassigned', 'slug' => 'unassigned']);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($assignedBrand, ['account_id' => $account->id, 'status' => 'active']);

        $this->actingAs($user);
        app(CurrentAccountContract::class)->set($account, $user);

        $this->expectException(AccessDeniedHttpException::class);

        app(CurrentBrandContract::class)->switch($unassignedBrand, $user);
    }

    public function test_brand_switch_rejects_inactive_brands_even_with_membership(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account', 'slug' => 'account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Inactive', 'slug' => 'inactive', 'status' => 'inactive']);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        $this->actingAs($user);
        app(CurrentAccountContract::class)->set($account, $user);

        $this->expectException(AccessDeniedHttpException::class);

        app(CurrentBrandContract::class)->switch($brand, $user);
    }

    public function test_http_account_switch_forbids_unpermitted_accounts(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Other', 'slug' => 'other']);

        $this->actingAs($user)
            ->post(route('tenant.account.switch'), ['account_id' => $account->id])
            ->assertForbidden();
    }

    public function test_http_brand_switch_forbids_unpermitted_brands(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account', 'slug' => 'account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Brand', 'slug' => 'brand']);

        $user->accounts()->attach($account, ['status' => 'active']);

        $this->actingAs($user)
            ->post(route('tenant.brand.switch'), ['brand_id' => $brand->id])
            ->assertForbidden();
    }

    public function test_stale_cached_account_id_falls_back_to_accessible_account(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Valid', 'slug' => 'valid']);

        $user->accounts()->attach($account, ['status' => 'active']);
        Cache::put("tenant-context:user:{$user->id}:account", 999, now()->addDay());

        $this->actingAs($user);

        $currentAccount = app(CurrentAccountContract::class)->get($user);

        $this->assertTrue($account->is($currentAccount));
    }

    public function test_account_scoped_models_are_filtered_by_current_account(): void
    {
        Schema::create('tenant_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('name');
        });

        $user = User::factory()->create();
        $firstAccount = Account::query()->create(['name' => 'First', 'slug' => 'first']);
        $secondAccount = Account::query()->create(['name' => 'Second', 'slug' => 'second']);

        $user->accounts()->attach($firstAccount, ['status' => 'active']);
        $user->accounts()->attach($secondAccount, ['status' => 'active']);

        $model = new class extends Model
        {
            use BelongsToAccount;

            public $timestamps = false;

            protected $guarded = [];

            protected $table = 'tenant_records';
        };

        $model->newQueryWithoutScopes()->create(['account_id' => $firstAccount->id, 'name' => 'First record']);
        $model->newQueryWithoutScopes()->create(['account_id' => $secondAccount->id, 'name' => 'Second record']);

        $this->actingAs($user);
        app(CurrentAccountContract::class)->set($firstAccount, $user);

        $this->assertSame(['First record'], $model->newQuery()->pluck('name')->all());

        app(CurrentAccountContract::class)->set($secondAccount, $user);

        $this->assertSame(['Second record'], $model->newQuery()->pluck('name')->all());
    }

    public function test_brand_scoped_models_are_filtered_by_current_brand(): void
    {
        Schema::create('brand_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->string('name');
        });

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account', 'slug' => 'account']);
        $firstBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'First', 'slug' => 'first']);
        $secondBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Second', 'slug' => 'second']);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($firstBrand, ['account_id' => $account->id, 'status' => 'active']);
        $user->brands()->attach($secondBrand, ['account_id' => $account->id, 'status' => 'active']);

        $model = new class extends Model
        {
            use BelongsToBrand;

            public $timestamps = false;

            protected $guarded = [];

            protected $table = 'brand_records';
        };

        $model->newQueryWithoutScopes()->create(['brand_id' => $firstBrand->id, 'name' => 'First record']);
        $model->newQueryWithoutScopes()->create(['brand_id' => $secondBrand->id, 'name' => 'Second record']);

        $this->actingAs($user);
        app(CurrentAccountContract::class)->set($account, $user);
        app(CurrentBrandContract::class)->set($firstBrand, $user);

        $this->assertSame(['First record'], $model->newQuery()->pluck('name')->all());

        app(CurrentBrandContract::class)->set($secondBrand, $user);

        $this->assertSame(['Second record'], $model->newQuery()->pluck('name')->all());
    }
}
