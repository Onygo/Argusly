<?php

namespace Tests\Feature;

use App\Jobs\CalculateContentLifecycleScoreJob;
use App\Jobs\GenerateContentAssetJob;
use App\Jobs\PublishContentAssetJob;
use App\Jobs\RunContentAuditJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\CreditTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreditUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_actions_deduct_placeholder_credits_and_write_transactions(): void
    {
        Queue::fake();

        [$publisher, $account, $brand] = $this->tenantWithRole('publisher');
        app(CreditService::class)->grant($account, 140, $publisher, 'Test credits');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['status' => 'approved']);

        $this->actingAs($publisher)->post(route('app.content.generate', $asset), ['type' => 'refresh'])->assertRedirect();
        $this->actingAs($publisher)->post(route('app.content.audit', $asset))->assertRedirect();
        $this->actingAs($publisher)->post(route('app.content.lifecycle', $asset))->assertRedirect();
        $this->actingAs($publisher)->post(route('app.content.publish', $asset))->assertRedirect();

        $this->assertSame(0, app(CreditService::class)->balance($account));
        $this->assertDatabaseHas('credit_transactions', ['account_id' => $account->id, 'user_id' => $publisher->id, 'type' => 'content_generation', 'amount' => -100]);
        $this->assertDatabaseHas('credit_transactions', ['account_id' => $account->id, 'user_id' => $publisher->id, 'type' => 'content_audit', 'amount' => -25]);
        $this->assertDatabaseHas('credit_transactions', ['account_id' => $account->id, 'user_id' => $publisher->id, 'type' => 'content_lifecycle', 'amount' => -10]);
        $this->assertDatabaseHas('credit_transactions', ['account_id' => $account->id, 'user_id' => $publisher->id, 'type' => 'publishing_action', 'amount' => -5]);

        Queue::assertPushed(GenerateContentAssetJob::class);
        Queue::assertPushed(RunContentAuditJob::class);
        Queue::assertPushed(CalculateContentLifecycleScoreJob::class);
        Queue::assertPushed(PublishContentAssetJob::class);
    }

    public function test_insufficient_credits_prevent_dispatch_and_show_clean_error(): void
    {
        Queue::fake();

        [$editor, $account, $brand] = $this->tenantWithRole('editor');
        app(CreditService::class)->grant($account, 99, $editor, 'Almost enough credits');
        $asset = ContentAsset::factory()->forBrand($brand)->create();

        $this->actingAs($editor)
            ->from(route('app.content.show', $asset))
            ->post(route('app.content.generate', $asset), ['type' => 'refresh'])
            ->assertRedirect(route('app.content.show', $asset))
            ->assertSessionHasErrors('credits');

        $this->assertSame(99, app(CreditService::class)->balance($account));
        $this->assertSame(1, CreditTransaction::query()->where('account_id', $account->id)->count());
        $this->assertDatabaseMissing('generated_assets', ['content_asset_id' => $asset->id]);
        Queue::assertNotPushed(GenerateContentAssetJob::class);
    }

    public function test_dashboard_shows_available_credit_balance(): void
    {
        [$viewer, $account] = $this->tenantWithRole('viewer');
        app(CreditService::class)->grant($account, 321, $viewer, 'Dashboard credits');

        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('321')
            ->assertSee('Current account balance');
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => fake()->company(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => fake()->unique()->slug()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$user, $account, $brand];
    }
}
