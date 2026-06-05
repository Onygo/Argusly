<?php

namespace Tests\Feature;

use App\Jobs\GenerateContentAssetJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Briefing;
use App\Models\ContentAsset;
use App\Models\ContentLifecycleScore;
use App\Models\GeneratedAsset;
use App\Models\MarketingTask;
use App\Models\Newsletter;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\ContentOperationsService;
use App\Services\CreditService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\SocialProfiles\SocialProfileService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentOperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_briefing_workflow_creates_content_plan_and_draft(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $briefing = $this->briefing($account, $brand, $user);

        $this->actingAs($user)
            ->get(route('app.content.operations'))
            ->assertOk()
            ->assertSee('Content operations')
            ->assertSee('Briefing workflows')
            ->assertSee($briefing->title);

        $this->actingAs($user)
            ->post(route('app.content.operations.briefings.plan', $briefing))
            ->assertRedirect();

        $this->assertDatabaseHas('marketing_tasks', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Content plan: '.$briefing->title,
            'status' => 'todo',
        ]);

        $plan = MarketingTask::query()->where('title', 'Content plan: '.$briefing->title)->firstOrFail();
        $this->assertSame('briefing_to_content_plan', $plan->metadata['workflow']);

        $this->actingAs($user)
            ->post(route('app.content.operations.briefings.draft', $briefing))
            ->assertRedirect();

        $asset = ContentAsset::query()->where('title', $briefing->title)->firstOrFail();

        $this->assertSame('draft', $asset->status);
        $this->assertSame('briefing', $asset->source);
        $this->assertSame($briefing->id, $asset->metadata['briefing_id']);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'ContentDraftCreatedFromBriefing',
            'subject_id' => $asset->id,
        ]);
    }

    public function test_generation_can_be_queued_and_applied_to_a_draft(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Original draft',
            'status' => 'draft',
            'body' => 'Original body.',
        ]);

        $this->actingAs($user)
            ->post(route('app.content.generate-draft', $asset), ['type' => 'refresh'])
            ->assertRedirect();

        $generated = GeneratedAsset::query()->where('content_asset_id', $asset->id)->firstOrFail();
        Queue::assertPushed(GenerateContentAssetJob::class, fn (GenerateContentAssetJob $job) => $job->generatedAssetId === $generated->id);

        $generated->forceFill([
            'status' => 'completed',
            'title' => 'Generated draft',
            'body' => 'Generated body ready for review.',
        ])->save();

        $this->actingAs($user)
            ->post(route('app.content.generated-assets.apply', [$asset, $generated]))
            ->assertRedirect();

        $asset->refresh();

        $this->assertSame('Generated draft', $asset->title);
        $this->assertSame('Generated body ready for review.', $asset->body);
        $this->assertSame('review', $asset->status);
        $this->assertSame('approved', $generated->refresh()->status);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'GeneratedDraftApplied',
            'subject_id' => $asset->id,
        ]);
    }

    public function test_distribution_bundle_creates_social_post_and_newsletter_section(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $this->linkedInProfile($user, $account, $brand);
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Distribution-ready article',
            'status' => 'approved',
            'excerpt' => 'A useful summary for distribution.',
            'body' => 'Longer source body.',
            'language' => 'en',
            'locale' => 'en_US',
        ]);

        $this->actingAs($user)
            ->post(route('app.content.distribution-bundle', $asset))
            ->assertRedirect();

        $this->assertDatabaseHas('social_posts', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'content_asset_id' => $asset->id,
            'status' => 'draft',
        ]);
        $newsletter = Newsletter::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->firstOrFail();

        $this->assertDatabaseHas('newsletter_sections', [
            'newsletter_id' => $newsletter->id,
            'content_asset_id' => $asset->id,
            'type' => 'content_asset',
        ]);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'ContentDistributionBundlePrepared',
            'subject_id' => $asset->id,
        ]);
    }

    public function test_lifecycle_score_can_create_refresh_recommendation(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Decaying guide']);
        $score = ContentLifecycleScore::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'content_asset_id' => $asset->id,
            'language' => 'en',
            'locale' => 'en_US',
            'status' => 'needs_refresh',
            'health_score' => 42,
            'freshness_score' => 35,
            'performance_score' => 45,
            'visibility_score' => 50,
            'refresh_priority' => 88,
            'reason' => 'This guide is stale and losing performance.',
            'signals' => ['recommendations' => ['refresh content']],
            'scored_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('app.content.operations.lifecycle.recommendation', $score))
            ->assertRedirect();

        $recommendation = Recommendation::query()->where('title', 'Refresh Decaying guide')->firstOrFail();

        $this->assertSame('refresh_content', $recommendation->action_type);
        $this->assertSame($asset->id, $recommendation->action_payload['content_asset_id']);

        app(ContentOperationsService::class)->createRefreshRecommendation($score, $user);
        $this->assertSame(1, Recommendation::query()->where('title', 'Refresh Decaying guide')->count());
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantWithRole(string $roleName): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => fake()->company(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Content Ops Brand',
            'slug' => fake()->unique()->slug(),
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en', 'nl'],
        ]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');
        app(CreditService::class)->grant($account, 5000, $user, 'Content operations test credits');

        return [$user, $account, $brand];
    }

    private function briefing(Account $account, Brand $brand, User $user): Briefing
    {
        return Briefing::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'AI visibility launch briefing',
            'objective' => 'Create a campaign article for enterprise buyers.',
            'audience' => 'Enterprise marketing teams',
            'tone_of_voice' => 'Clear',
            'key_message' => 'Argusly turns intelligence into execution.',
            'channels' => ['blog', 'linkedin', 'email'],
            'languages' => ['en'],
            'status' => 'approved',
            'created_by' => $user->id,
        ]);
    }

    private function linkedInProfile(User $user, Account $account, Brand $brand): SocialProfile
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $user,
            integration: 'linkedin',
            name: 'Content Ops LinkedIn',
            scopes: ['openid', 'profile', 'email', 'w_member_social'],
            accessToken: 'linkedin-token',
            providerAccountId: 'content-ops-linkedin',
        );

        return app(SocialProfileService::class)->createFromIntegrationConnection(
            connection: $connection,
            owner: $user,
            provider: 'linkedin',
            displayName: 'Content Ops LinkedIn',
            type: 'person',
            providerProfileId: 'content-ops-linkedin',
            account: $account,
            brand: $brand,
        );
    }
}
