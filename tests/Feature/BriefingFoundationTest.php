<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Briefing;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BriefingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_briefing_can_be_created_and_attached_to_campaign(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $campaign = $this->campaign($account, $brand);

        $this->actingAs($user)
            ->post(route('app.briefings.store'), [
                'scope' => 'brand',
                'campaign_id' => $campaign->id,
                'title' => 'Q3 launch briefing',
                'objective' => 'Create a clear demand generation story.',
                'audience' => 'Marketing leaders',
                'tone_of_voice' => 'Confident and practical',
                'key_message' => 'Argusly turns planning into execution.',
                'channels' => ['blog', 'linkedin'],
                'languages' => ['en', 'nl'],
                'status' => 'draft',
            ])
            ->assertRedirect();

        $briefing = Briefing::query()->where('title', 'Q3 launch briefing')->firstOrFail();

        $this->assertSame($account->id, $briefing->account_id);
        $this->assertSame($brand->id, $briefing->brand_id);
        $this->assertSame($campaign->id, $briefing->campaign_id);
        $this->assertSame(['blog', 'linkedin'], $briefing->channels);
        $this->assertSame(['en', 'nl'], $briefing->languages);

        $this->actingAs($user)
            ->get(route('app.briefings.show', $briefing))
            ->assertOk()
            ->assertSee('Q3 launch briefing')
            ->assertSee('Marketing leaders')
            ->assertSee('Argusly turns planning into execution.');
    }

    public function test_briefing_languages_must_be_enabled_for_brand(): void
    {
        [, $account, $brand] = $this->tenantUser('owner');

        $this->expectException(InvalidArgumentException::class);

        Briefing::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Invalid language brief',
            'languages' => ['de'],
            'status' => 'draft',
        ]);
    }

    public function test_briefing_index_and_detail_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', 'other-briefing-account');

        $visible = Briefing::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Visible briefing',
            'status' => 'draft',
        ]);
        $hidden = Briefing::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'title' => 'Hidden briefing',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('app.briefings'))
            ->assertOk()
            ->assertSee($visible->title)
            ->assertDontSee($hidden->title);

        $this->actingAs($user)
            ->get(route('app.briefings.show', $hidden))
            ->assertForbidden();
    }

    public function test_briefing_supports_approval_flow(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $briefing = Briefing::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Approval briefing',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('app.briefings.approval.request', $briefing))
            ->assertRedirect(route('app.briefings.show', $briefing));

        $briefing->refresh();
        $this->assertSame('review', $briefing->status);
        $this->assertDatabaseHas('approvals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => $briefing->getMorphClass(),
            'subject_id' => $briefing->id,
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->post(route('app.briefings.approve', $briefing))
            ->assertRedirect(route('app.briefings.show', $briefing));

        $briefing->refresh();
        $this->assertSame('approved', $briefing->status);
        $this->assertSame($user->id, $briefing->approved_by);
        $this->assertNotNull($briefing->approved_at);
        $this->assertSame('approved', Approval::query()->where('subject_type', $briefing->getMorphClass())->where('subject_id', $briefing->id)->firstOrFail()->status);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, string $slug = 'briefing-account'): array
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
        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        return [$user, $account, $brand];
    }

    private function campaign(Account $account, Brand $brand): Campaign
    {
        return Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Briefing campaign',
            'slug' => fake()->unique()->slug(),
            'status' => 'active',
            'metadata' => ['campaign_type' => 'content'],
        ]);
    }
}
