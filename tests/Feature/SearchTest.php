<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContentAsset;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Topic;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_returns_tenant_scoped_results(): void
    {
        [$user, $account, $brand] = $this->tenant();
        $otherAccount = Account::query()->create(['name' => 'Other Account', 'slug' => 'other-account']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);

        ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Apollo visibility playbook',
            'excerpt' => 'Content for Apollo search visibility.',
        ]);
        ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Apollo hidden asset']);

        Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Apollo launch campaign',
            'description' => 'Campaign around Apollo visibility.',
            'status' => 'active',
        ]);

        Contact::query()->create([
            'account_id' => $account->id,
            'first_name' => 'Ada',
            'last_name' => 'Apollo',
            'email' => 'ada@example.com',
        ]);

        Organization::query()->create([
            'account_id' => $account->id,
            'name' => 'Apollo Partners',
            'industry' => 'Research',
        ]);

        Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Apollo AI visibility',
            'description' => 'Topic around answer coverage.',
        ]);

        $this->actingAs($user)
            ->get(route('app.search', ['q' => 'Apollo']))
            ->assertOk()
            ->assertSee('Apollo visibility playbook')
            ->assertSee('Apollo launch campaign')
            ->assertSee('Ada Apollo')
            ->assertSee('Apollo Partners')
            ->assertSee('Apollo AI visibility')
            ->assertDontSee('Apollo hidden asset');
    }

    public function test_global_search_form_is_enabled(): void
    {
        [$user] = $this->tenant();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('app.search'), false)
            ->assertDontSee('disabled placeholder="Search content, campaigns, contacts, topics..."', false);
    }

    public function test_global_search_returns_current_workspace_results(): void
    {
        [$user] = $this->tenant();

        $this->actingAs($user)
            ->get(route('app.search', ['q' => 'Search']))
            ->assertOk()
            ->assertSee('Search Account')
            ->assertSee('Search Brand')
            ->assertSee('Account')
            ->assertSee('Brand');
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenant(): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Search Account', 'slug' => 'search-account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Search Brand', 'slug' => 'search-brand']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$user, $account, $brand];
    }
}
