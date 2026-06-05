<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\BrandNarrative;
use App\Models\BrandProfile;
use App\Models\BrandService;
use App\Models\ContentAsset;
use App\Models\Role;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_index_is_tenant_isolated(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Beta Brand', 'slug' => 'beta-brand']);

        ContentAsset::factory()->forBrand($brand)->create(['title' => 'Visible tenant asset', 'status' => 'draft']);
        ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden tenant asset', 'status' => 'draft']);

        $this->actingAs($user)
            ->get(route('app.content.index'))
            ->assertOk()
            ->assertSee('Visible tenant asset')
            ->assertDontSee('Hidden tenant asset');
    }

    public function test_content_index_and_show_are_brand_isolated(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $user->brands()->attach($otherBrand, ['account_id' => $account->id, 'status' => 'active']);

        $visible = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Visible brand asset', 'status' => 'draft']);
        $hidden = ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden brand asset', 'status' => 'draft']);

        $this->actingAs($user)
            ->get(route('app.content.index'))
            ->assertOk()
            ->assertSee($visible->title)
            ->assertDontSee($hidden->title);

        $this->actingAs($user)
            ->get(route('app.content.show', $hidden))
            ->assertForbidden();
    }

    public function test_content_module_access_is_required(): void
    {
        [$user] = $this->tenantWithRole('owner', activatePlan: false);

        $this->actingAs($user)
            ->get(route('app.content.index'))
            ->assertForbidden();
    }

    public function test_viewer_cannot_edit_content_assets(): void
    {
        [$viewer, , $brand] = $this->tenantWithRole('viewer');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['status' => 'draft']);

        $this->actingAs($viewer)
            ->get(route('app.content.edit', $asset))
            ->assertForbidden();
    }

    public function test_editor_can_create_and_edit_content_assets(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor');

        $this->actingAs($editor)
            ->post(route('app.content.store'), $this->assetPayload(['title' => 'Editor created content asset']))
            ->assertRedirect();

        $asset = ContentAsset::query()->where('title', 'Editor created content asset')->firstOrFail();

        $this->assertSame($brand->id, $asset->brand_id);
        $this->assertSame('draft', $asset->status);

        $this->actingAs($editor)
            ->put(route('app.content.update', $asset), $this->assetPayload(['title' => 'Editor updated content asset']))
            ->assertRedirect(route('app.content.show', $asset));

        $this->assertDatabaseHas('content_assets', [
            'id' => $asset->id,
            'title' => 'Editor updated content asset',
        ]);
    }

    public function test_create_page_prioritizes_guided_first_draft_controls(): void
    {
        [$editor] = $this->tenantWithRole('editor');

        $this->actingAs($editor)
            ->get(route('app.content.create'))
            ->assertOk()
            ->assertSee('Generate first draft')
            ->assertSee('Primary keyword')
            ->assertSee('Chained content')
            ->assertSee('Number of drafts')
            ->assertSee('Use 1 for a single draft')
            ->assertSee('Context used')
            ->assertSee('Create manual asset')
            ->assertSee('Credits')
            ->assertSee('1,000')
            ->assertDontSee('Locale');
    }

    public function test_editor_can_create_guided_first_draft_from_keyword_and_brand_context(): void
    {
        [$editor, $account, $brand] = $this->tenantWithRole('editor');
        BrandProfile::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'official_name' => 'Alpha Labs',
            'short_description' => 'Alpha Labs helps teams improve AI visibility.',
            'tone_of_voice' => 'Practical, direct and evidence-led.',
            'primary_audience' => 'Marketing leaders',
            'value_proposition' => 'Clear workflows for answer-ready content.',
        ]);
        BrandService::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Content intelligence',
            'description' => 'Finds content gaps before competitors do.',
            'status' => 'active',
        ]);

        $response = $this->actingAs($editor)
            ->post(route('app.content.store'), [
                'creation_mode' => 'guided_first_draft',
                'draft_mode' => 'single',
                'title' => 'AI visibility roadmap',
                'primary_keyword' => 'AI visibility',
                'secondary_keywords' => 'answer engine optimization, GEO',
                'angle' => 'Make it useful for teams planning next quarter.',
                'audience' => 'B2B marketing teams',
                'type' => 'article',
                'language' => 'en',
            ]);

        $asset = ContentAsset::query()->where('title', 'AI visibility roadmap')->firstOrFail();
        $response->assertRedirect(route('app.content.show', $asset));

        $this->assertSame('draft', $asset->status);
        $this->assertSame('en_US', $asset->locale);
        $this->assertSame('guided_first_draft', $asset->source);
        $this->assertSame('guided_first_draft', $asset->metadata['workflow']);
        $this->assertSame('AI visibility', $asset->metadata['primary_keyword']);
        $this->assertSame(['answer engine optimization', 'GEO'], $asset->metadata['secondary_keywords']);
        $this->assertContains('company_profile', $asset->metadata['context_sources']);
        $this->assertContains('services', $asset->metadata['context_sources']);
        $this->assertStringContainsString('Practical, direct and evidence-led.', $asset->body);
        $this->assertStringContainsString('Content intelligence', $asset->body);
    }

    public function test_editor_can_create_single_guided_first_draft_with_chain_count_one(): void
    {
        [$editor] = $this->tenantWithRole('editor');

        $this->actingAs($editor)
            ->post(route('app.content.store'), [
                'creation_mode' => 'guided_first_draft',
                'draft_mode' => 'single',
                'primary_keyword' => 'agentic marketing',
                'chain_count' => 1,
                'type' => 'article',
                'language' => 'en',
            ])
            ->assertRedirect();

        $asset = ContentAsset::query()->where('source', 'guided_first_draft')->firstOrFail();

        $this->assertSame('single', $asset->metadata['draft_mode']);
        $this->assertNull($asset->metadata['chain_count']);
        $this->assertSame('Agentic Marketing', $asset->title);
    }

    public function test_chained_guided_first_draft_requires_at_least_two_items(): void
    {
        [$editor] = $this->tenantWithRole('editor');

        $this->actingAs($editor)
            ->from(route('app.content.create'))
            ->post(route('app.content.store'), [
                'creation_mode' => 'guided_first_draft',
                'draft_mode' => 'chain',
                'primary_keyword' => 'agentic marketing',
                'chain_count' => 1,
                'type' => 'article',
                'language' => 'en',
            ])
            ->assertRedirect(route('app.content.create'))
            ->assertSessionHasErrors('chain_count');

        $this->assertSame(0, ContentAsset::query()->where('source', 'guided_first_draft')->count());
    }

    public function test_content_locale_is_inferred_from_selected_language(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor');
        $brand->update([
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en', 'nl'],
        ]);

        $this->actingAs($editor)
            ->post(route('app.content.store'), $this->assetPayload([
                'title' => 'Nederlandse content',
                'language' => 'nl',
                'locale' => null,
            ]))
            ->assertRedirect();

        $asset = ContentAsset::query()->where('title', 'Nederlandse content')->firstOrFail();

        $this->assertSame('nl', $asset->language);
        $this->assertSame('nl_NL', $asset->locale);
    }

    public function test_guided_first_draft_locale_is_inferred_from_selected_language(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor');
        $brand->update([
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en', 'nl'],
        ]);

        $this->actingAs($editor)
            ->post(route('app.content.store'), [
                'creation_mode' => 'guided_first_draft',
                'draft_mode' => 'single',
                'primary_keyword' => 'contentstrategie',
                'type' => 'article',
                'language' => 'nl',
            ])
            ->assertRedirect();

        $asset = ContentAsset::query()->where('source', 'guided_first_draft')->firstOrFail();

        $this->assertSame('nl', $asset->language);
        $this->assertSame('nl_NL', $asset->locale);
    }

    public function test_editor_can_create_chained_guided_first_drafts(): void
    {
        [$editor, $account, $brand] = $this->tenantWithRole('editor');
        BrandProfile::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'official_name' => 'Alpha Labs',
            'short_description' => 'Alpha Labs helps teams improve AI visibility.',
            'tone_of_voice' => 'Practical and concise.',
        ]);
        BrandNarrative::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'AI-first marketing operations',
            'description' => 'Modern teams need integrated intelligence and execution.',
            'importance' => 'high',
            'status' => 'active',
        ]);

        $this->actingAs($editor)
            ->post(route('app.content.store'), [
                'creation_mode' => 'guided_first_draft',
                'draft_mode' => 'chain',
                'primary_keyword' => 'content planning',
                'chain_count' => 3,
                'type' => 'article',
                'language' => 'en',
                'locale' => 'en_US',
            ])
            ->assertRedirect();

        $assets = ContentAsset::query()
            ->where('source', 'guided_first_draft')
            ->where('metadata->primary_keyword', 'content planning')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $assets);
        $chainId = $assets->first()->metadata['chain_id'];
        $this->assertNotEmpty($chainId);

        $assets->each(function (ContentAsset $asset, int $index) use ($chainId): void {
            $this->assertSame($chainId, $asset->metadata['chain_id']);
            $this->assertSame($index + 1, $asset->metadata['chain_position']);
            $this->assertSame(3, $asset->metadata['chain_count']);
            $this->assertStringContainsString('article '.($index + 1).' of 3', $asset->body);
        });
    }

    public function test_publisher_and_admin_can_approve_and_publish_content_assets(): void
    {
        [$publisher, , $publisherBrand] = $this->tenantWithRole('publisher');
        $publisherAsset = ContentAsset::factory()->forBrand($publisherBrand)->create(['status' => 'review']);

        $this->actingAs($publisher)
            ->post(route('app.content.approve', $publisherAsset))
            ->assertRedirect(route('app.content.show', $publisherAsset));

        $this->assertDatabaseHas('content_assets', [
            'id' => $publisherAsset->id,
            'status' => 'approved',
        ]);

        [$admin, , $adminBrand] = $this->tenantWithRole('admin', slug: 'admin-account');
        $adminAsset = ContentAsset::factory()->forBrand($adminBrand)->create(['status' => 'approved']);

        $this->actingAs($admin)
            ->post(route('app.content.publish', $adminAsset))
            ->assertRedirect(route('app.content.show', $adminAsset));

        $adminAsset->refresh();

        $this->assertSame('published', $adminAsset->status);
        $this->assertNotNull($adminAsset->published_at);
        $this->assertNotNull($adminAsset->first_published_at);
    }

    public function test_editor_can_queue_generation_for_existing_content_asset(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'type' => 'article',
            'status' => 'draft',
            'title' => 'Existing article',
            'language' => 'en',
            'locale' => 'en_US',
        ]);

        $this->actingAs($editor)
            ->post(route('app.content.generate', $asset), [
                'type' => 'article',
                'prompt' => 'Improve this article.',
                'language' => 'en',
            ])
            ->assertRedirect(route('app.content.show', $asset));

        $this->assertDatabaseHas('generated_assets', [
            'content_asset_id' => $asset->id,
            'status' => 'completed',
            'type' => 'article',
            'cost_credits' => 100,
        ]);
        $this->assertDatabaseHas('credit_transactions', [
            'account_id' => $asset->account_id,
            'user_id' => $editor->id,
            'type' => 'content_generation',
            'amount' => -100,
        ]);
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName, bool $activatePlan = true, string $slug = 'alpha-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->replace('-', ' ')->headline(), 'slug' => $slug]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => str($slug)->headline().' Brand', 'slug' => $slug.'-brand']);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        if ($activatePlan) {
            app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
            app(CreditService::class)->grant($account, 1000, $user, 'Test credits');
        }

        return [$user, $account, $brand];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assetPayload(array $overrides = []): array
    {
        return [
            'type' => 'article',
            'status' => 'draft',
            'title' => 'Content asset placeholder',
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
            'excerpt' => 'Placeholder excerpt.',
            'body' => 'Placeholder body.',
            ...$overrides,
        ];
    }
}
