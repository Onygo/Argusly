<?php

namespace Tests\Feature;

use App\Jobs\GenerateContentAssetJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\GeneratedAsset;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentGenerationService;
use App\Services\CreditService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_action_creates_queued_generated_asset_and_dispatches_job(): void
    {
        Queue::fake();

        [$editor, , $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'type' => 'article',
            'title' => 'AI visibility guide',
            'language' => 'en',
        ]);

        $this->actingAs($editor)
            ->post(route('app.content.generate', $asset), [
                'type' => 'refresh',
                'prompt' => 'Refresh this asset for a founder audience.',
            ])
            ->assertRedirect(route('app.content.show', $asset));

        $generatedAsset = GeneratedAsset::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame($asset->account_id, $generatedAsset->account_id);
        $this->assertSame($asset->brand_id, $generatedAsset->brand_id);
        $this->assertSame('refresh', $generatedAsset->type);
        $this->assertSame('queued', $generatedAsset->status);
        $this->assertSame('openai', $generatedAsset->provider);
        $this->assertSame('gpt-4.1-mini', $generatedAsset->model);

        Queue::assertPushed(
            GenerateContentAssetJob::class,
            fn (GenerateContentAssetJob $job) => $job->generatedAssetId === $generatedAsset->id,
        );
    }

    public function test_generation_job_stores_static_output(): void
    {
        Queue::fake();

        [$editor, , $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Search demand article']);
        $generatedAsset = app(ContentGenerationService::class)->requestForContentAsset($asset, $editor, ['type' => 'article']);

        (new GenerateContentAssetJob($generatedAsset->id))->handle(app(ContentGenerationService::class));

        $generatedAsset->refresh();

        $this->assertSame('completed', $generatedAsset->status);
        $this->assertSame(100, $generatedAsset->cost_credits);
        $this->assertStringContainsString('Search demand article', $generatedAsset->title);
        $this->assertStringContainsString('without calling a real AI provider', $generatedAsset->body);
        $this->assertTrue($generatedAsset->output_payload['fake']);
        $this->assertSame('openai', $generatedAsset->output_payload['llm_response']['provider']);
    }

    public function test_generation_history_is_tenant_and_brand_isolated(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('editor');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $user->brands()->attach($otherBrand, ['account_id' => $account->id, 'status' => 'active']);

        $visibleAsset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Visible content asset']);
        $hiddenAsset = ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden content asset']);

        GeneratedAsset::factory()->forContentAsset($visibleAsset)->create([
            'title' => 'Visible generation run',
            'status' => 'completed',
        ]);
        GeneratedAsset::factory()->forContentAsset($hiddenAsset)->create([
            'title' => 'Hidden generation run',
            'status' => 'completed',
        ]);

        $this->actingAs($user)
            ->get(route('app.content.show', $visibleAsset))
            ->assertOk()
            ->assertSee('Visible generation run')
            ->assertDontSee('Hidden generation run');

        $this->actingAs($user)
            ->post(route('app.content.generate', $hiddenAsset), ['type' => 'refresh'])
            ->assertForbidden();
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
        app(CreditService::class)->grant($account, 1000, $user, 'Test credits');

        return [$user, $account, $brand];
    }
}
