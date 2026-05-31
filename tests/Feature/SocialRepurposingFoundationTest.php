<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\CreditBalance;
use App\Models\Role;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\SocialProfiles\SocialProfileService;
use App\Services\SocialRepurposing\SocialRepurposingService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SocialRepurposingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_three_language_aware_variants_and_deducts_credits(): void
    {
        [$owner, $user, $account, $brand, $profile, $asset] = $this->repurposingContext();
        app(CreditService::class)->grant($account, 50, $owner, 'Test grant');
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
        ]);

        $post = app(SocialRepurposingService::class)->generateFromContentAsset($account, $brand, $user, $asset, $profile, 'nl');

        $this->assertSame(40, CreditBalance::query()->where('account_id', $account->id)->value('balance'));
        $this->assertSame($asset->id, $post->content_asset_id);
        $this->assertSame('nl', $post->language);
        $this->assertCount(3, $post->variants);
        $this->assertEqualsCanonicalizing(['short', 'linkedin_personal', 'thread'], $post->variants->pluck('variant_type')->all());
        $this->assertSame(['nl'], $post->variants->pluck('language')->unique()->values()->all());
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SocialPostVariantsGenerated',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
    }

    public function test_selecting_variant_sets_final_social_post_text(): void
    {
        [$owner, $user, $account, $brand, $profile, $asset] = $this->repurposingContext();
        app(CreditService::class)->grant($account, 50, $owner, 'Test grant');
        app(SocialProfileService::class)->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
        ]);
        $post = app(SocialRepurposingService::class)->generateFromContentAsset($account, $brand, $user, $asset, $profile, 'en');
        $variant = $post->variants()->where('variant_type', 'thread')->firstOrFail();

        $selected = app(SocialRepurposingService::class)->selectVariant($post, $variant, $user);

        $this->assertSame($variant->post_text, $selected->post_text);
        $this->assertSame('draft', $selected->status);
        $this->assertSame('selected', $variant->fresh()->status);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SocialPostVariantSelected',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
    }

    public function test_repurposing_is_tenant_safe(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->repurposingContext();
        app(CreditService::class)->grant($account, 50, $owner, 'Test grant');
        app(SocialProfileService::class)->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
        ]);
        $otherAccount = Account::query()->create(['name' => 'Other', 'slug' => 'other']);
        $otherBrand = Brand::query()->create([
            'account_id' => $otherAccount->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
            'enabled_content_languages' => ['en'],
            'default_content_language' => 'en',
        ]);
        $otherAsset = $this->asset($otherAccount, $otherBrand);

        $this->expectException(InvalidArgumentException::class);

        app(SocialRepurposingService::class)->generateFromContentAsset($account, $brand, $user, $otherAsset, $profile, 'en');
    }

    public function test_repurposing_rejects_language_not_enabled_for_brand(): void
    {
        [$owner, $user, $account, $brand, $profile, $asset] = $this->repurposingContext();
        $brand->update(['enabled_content_languages' => ['en']]);
        app(CreditService::class)->grant($account, 50, $owner, 'Test grant');
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(SocialRepurposingService::class)->generateFromContentAsset($account, $brand, $user, $asset, $profile, 'nl');
    }

    public function test_repurposing_ui_shows_variants_and_converts_selected_variant(): void
    {
        [$owner, $user, $account, $brand, $profile, $asset] = $this->repurposingContext(withRole: true);
        app(CreditService::class)->grant($account, 50, $owner, 'Test grant');
        app(SocialProfileService::class)->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
        ]);

        $this->actingAs($user)
            ->get(route('app.content.social-posts.repurpose', $asset))
            ->assertOk()
            ->assertSee('Generate variants');

        $response = $this->actingAs($user)->post(route('app.content.social-posts.repurpose.store', $asset), [
            'social_profile_id' => $profile->id,
            'language' => 'en',
        ]);

        $post = $asset->socialPosts()->latest()->firstOrFail();
        $response->assertRedirect(route('app.social-posts.variants', $post));

        $variant = $post->variants()->firstOrFail();

        $this->actingAs($user)
            ->get(route('app.social-posts.variants', $post))
            ->assertOk()
            ->assertSee('Select a variant')
            ->assertSee($variant->post_text);

        $this->actingAs($user)
            ->post(route('app.social-posts.variants.select', [$post, $variant]))
            ->assertRedirect(route('app.social-posts.show', $post));

        $this->assertSame($variant->post_text, $post->fresh()->post_text);
    }

    /**
     * @return array{0: User, 1: User, 2: Account, 3: Brand, 4: SocialProfile, 5: ContentAsset}
     */
    private function repurposingContext(bool $withRole = false): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(IntegrationCatalogSeeder::class);

        if ($withRole) {
            $this->seed(RolesAndPermissionsSeeder::class);
            $this->seed(SubscriptionCatalogSeeder::class);
        }

        $owner = User::factory()->create(['name' => 'Ricardo']);
        $user = User::factory()->create(['name' => 'Editor']);
        $account = Account::query()->create(['name' => 'Onygo', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Argusly',
            'slug' => fake()->unique()->slug(),
            'market' => 'Netherlands',
            'enabled_content_languages' => ['en', 'nl'],
            'default_content_language' => 'en',
        ]);

        foreach ([$owner, $user] as $member) {
            $member->accounts()->attach($account, ['status' => 'active']);
            $member->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        }

        if ($withRole) {
            $role = Role::query()->where('name', 'owner')->firstOrFail();
            $user->roles()->attach($role, ['account_id' => $account->id]);
            app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');
        }

        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'linkedin',
            name: 'Ricardo LinkedIn',
            scopes: ['openid', 'profile', 'email', 'w_member_social'],
            accessToken: 'linkedin-token',
            providerAccountId: 'linkedin-ricardo',
        );

        $profile = app(SocialProfileService::class)->createFromIntegrationConnection(
            connection: $connection,
            owner: $owner,
            provider: 'linkedin',
            displayName: 'Ricardo LinkedIn',
            type: 'person',
            providerProfileId: 'linkedin-ricardo',
        );

        return [$owner, $user, $account, $brand, $profile, $this->asset($account, $brand)];
    }

    private function asset(Account $account, Brand $brand): ContentAsset
    {
        return ContentAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => 'article',
            'status' => 'approved',
            'title' => 'Social visibility playbook',
            'slug' => fake()->unique()->slug(),
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
            'excerpt' => 'A practical playbook for turning content into social visibility.',
            'body' => 'This asset explains how teams can repurpose long-form content into social posts, threads, and campaign updates.',
        ]);
    }
}
