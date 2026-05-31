<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\Newsletter;
use App\Models\NewsletterSection;
use App\Models\Approval;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class NewsletterFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_newsletter_can_be_created_for_enabled_brand_language_and_campaign(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $campaign = $this->campaign($account, $brand);

        $this->actingAs($user)
            ->post(route('app.newsletters.store'), [
                'campaign_id' => $campaign->id,
                'title' => 'June intelligence digest',
                'subject' => 'What changed in AI visibility this week',
                'preheader' => 'Signals, actions and content to ship next.',
                'language' => 'nl',
                'status' => 'draft',
            ])
            ->assertRedirect();

        $newsletter = Newsletter::query()->where('title', 'June intelligence digest')->firstOrFail();

        $this->assertSame($account->id, $newsletter->account_id);
        $this->assertSame($brand->id, $newsletter->brand_id);
        $this->assertSame($campaign->id, $newsletter->campaign_id);
        $this->assertSame('nl', $newsletter->language);

        $this->actingAs($user)
            ->get(route('app.newsletters.show', $newsletter))
            ->assertOk()
            ->assertSee('June intelligence digest')
            ->assertSee('What changed in AI visibility this week');
    }

    public function test_newsletter_language_must_be_enabled_for_brand(): void
    {
        [, $account, $brand] = $this->tenantUser('owner');

        $this->expectException(InvalidArgumentException::class);

        Newsletter::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Invalid language newsletter',
            'language' => 'de',
            'status' => 'draft',
        ]);
    }

    public function test_newsletter_sections_validate_types_and_content_asset_tenant_scope(): void
    {
        [, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', activatePlan: true, slug: 'other-newsletter-account');
        $newsletter = $this->newsletter($account, $brand);
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Visible asset']);
        $otherAsset = ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden asset']);

        $this->assertNotSame($account->id, $otherAccount->id);

        $section = NewsletterSection::query()->create([
            'newsletter_id' => $newsletter->id,
            'type' => 'content_asset',
            'title' => 'Lead asset',
            'content_asset_id' => $asset->id,
            'position' => 1,
        ]);

        $this->assertSame('Lead asset', $section->title);

        $this->expectException(InvalidArgumentException::class);

        NewsletterSection::query()->create([
            'newsletter_id' => $newsletter->id,
            'type' => 'content_asset',
            'content_asset_id' => $otherAsset->id,
            'position' => 2,
        ]);
    }

    public function test_newsletter_index_detail_and_sections_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', activatePlan: true, slug: 'hidden-newsletter-account');

        $visible = $this->newsletter($account, $brand, 'Visible newsletter');
        $hidden = $this->newsletter($otherAccount, $otherBrand, 'Hidden newsletter');

        $this->actingAs($user)
            ->get(route('app.newsletters'))
            ->assertOk()
            ->assertSee('Newsletters')
            ->assertSee($visible->title)
            ->assertDontSee($hidden->title);

        $this->actingAs($user)
            ->get(route('app.newsletters.show', $hidden))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('app.newsletters.sections.store', $visible), [
                'type' => 'intro',
                'title' => 'Opening note',
                'body' => 'Here is the editorial framing.',
            ])
            ->assertRedirect(route('app.newsletters.show', $visible));

        $this->assertDatabaseHas('newsletter_sections', [
            'newsletter_id' => $visible->id,
            'type' => 'intro',
            'title' => 'Opening note',
        ]);
    }

    public function test_newsletters_are_module_gated_and_visible_in_navigation(): void
    {
        [$user] = $this->tenantUser('owner');
        [$unsubscribedUser] = $this->tenantUser('owner', activatePlan: false, slug: 'newsletter-core-only');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Marketing')
            ->assertSee('Newsletters');

        $this->actingAs($unsubscribedUser)
            ->get(route('app.newsletters'))
            ->assertForbidden();
    }

    public function test_newsletter_builder_updates_envelope_and_is_language_aware(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $newsletter = $this->newsletter($account, $brand);
        $visibleAsset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Visible English asset',
            'language' => 'en',
            'excerpt' => 'English asset excerpt.',
        ]);
        ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Hidden Dutch asset',
            'language' => 'nl',
        ]);

        $this->actingAs($user)
            ->put(route('app.newsletters.update', $newsletter), [
                'title' => 'Weekly builder edition',
                'subject' => 'Builder subject',
                'preheader' => 'Builder preheader',
                'language' => 'en',
                'status' => 'draft',
            ])
            ->assertRedirect(route('app.newsletters.show', $newsletter));

        $newsletter->refresh();
        $this->assertSame('Builder subject', $newsletter->subject);
        $this->assertSame('Builder preheader', $newsletter->preheader);

        $this->actingAs($user)
            ->get(route('app.newsletters.show', $newsletter))
            ->assertOk()
            ->assertSee('Builder settings')
            ->assertSee('Preview newsletter')
            ->assertSee('Visible English asset')
            ->assertDontSee('Hidden Dutch asset');

        $this->actingAs($user)
            ->post(route('app.newsletters.sections.store', $newsletter), [
                'type' => 'content_asset',
                'content_asset_id' => $visibleAsset->id,
                'position' => 1,
            ])
            ->assertRedirect(route('app.newsletters.show', $newsletter));

        $this->assertDatabaseHas('newsletter_sections', [
            'newsletter_id' => $newsletter->id,
            'type' => 'content_asset',
            'title' => 'Visible English asset',
            'body' => 'English asset excerpt.',
            'content_asset_id' => $visibleAsset->id,
        ]);
    }

    public function test_newsletter_builder_reorders_sections_with_position_controls(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $newsletter = $this->newsletter($account, $brand);
        $first = NewsletterSection::query()->create([
            'newsletter_id' => $newsletter->id,
            'type' => 'intro',
            'title' => 'First section',
            'position' => 1,
        ]);
        $second = NewsletterSection::query()->create([
            'newsletter_id' => $newsletter->id,
            'type' => 'footer',
            'title' => 'Second section',
            'position' => 2,
        ]);

        $this->actingAs($user)
            ->post(route('app.newsletters.sections.reorder', $newsletter), [
                'positions' => [
                    $first->id => 20,
                    $second->id => 5,
                ],
            ])
            ->assertRedirect(route('app.newsletters.show', $newsletter));

        $this->assertSame(20, $first->refresh()->position);
        $this->assertSame(5, $second->refresh()->position);
        $this->assertSame(
            ['Second section', 'First section'],
            $newsletter->refresh()->sections()->pluck('title')->all(),
        );
    }

    public function test_newsletter_builder_save_draft_and_submit_for_approval(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $newsletter = $this->newsletter($account, $brand);
        $newsletter->forceFill([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->post(route('app.newsletters.draft', $newsletter))
            ->assertRedirect(route('app.newsletters.show', $newsletter));

        $newsletter->refresh();
        $this->assertSame('draft', $newsletter->status);
        $this->assertNull($newsletter->approved_by);
        $this->assertNull($newsletter->approved_at);

        $this->actingAs($user)
            ->post(route('app.newsletters.approval.request', $newsletter))
            ->assertRedirect(route('app.newsletters.show', $newsletter));

        $newsletter->refresh();
        $this->assertSame('review', $newsletter->status);
        $this->assertDatabaseHas('approvals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => $newsletter->getMorphClass(),
            'subject_id' => $newsletter->id,
            'status' => 'pending',
        ]);
        $this->assertSame(1, Approval::query()->where('subject_type', $newsletter->getMorphClass())->where('subject_id', $newsletter->id)->count());
    }

    public function test_newsletter_builder_update_is_tenant_safe(): void
    {
        [$user] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', activatePlan: true, slug: 'builder-hidden-account');
        $hidden = $this->newsletter($otherAccount, $otherBrand, 'Hidden builder newsletter');

        $this->actingAs($user)
            ->put(route('app.newsletters.update', $hidden), [
                'title' => 'Should not save',
                'subject' => 'Nope',
                'preheader' => 'Nope',
                'language' => 'en',
                'status' => 'draft',
            ])
            ->assertForbidden();

        $this->assertSame('Hidden builder newsletter', $hidden->refresh()->title);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, bool $activatePlan = true, string $slug = 'newsletter-account'): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->headline(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => str($slug)->headline().' Brand',
            'slug' => fake()->unique()->slug(),
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en', 'nl'],
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);

        if ($activatePlan) {
            app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');
        }

        return [$user, $account, $brand];
    }

    private function campaign(Account $account, Brand $brand): Campaign
    {
        return Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Newsletter campaign',
            'slug' => fake()->unique()->slug(),
            'status' => 'active',
            'metadata' => ['campaign_type' => 'email'],
        ]);
    }

    private function newsletter(Account $account, Brand $brand, string $title = 'Brand newsletter'): Newsletter
    {
        return Newsletter::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => $title,
            'subject' => 'Newsletter subject',
            'language' => 'en',
            'status' => 'draft',
        ]);
    }
}
