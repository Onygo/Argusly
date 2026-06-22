<?php

use App\Enums\CampaignContentAssetType;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Enums\SocialPublicationStatus;
use App\Jobs\SocialDistribution\PublishSocialPostJob;
use App\Models\Campaign;
use App\Models\CampaignContent;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CampaignPlanning\CampaignAssetGenerationService;
use App\Services\SocialDistribution\InstagramPostTextRenderer;
use App\Services\SocialDistribution\SocialDistributionAuditLogger;
use App\Services\SocialDistribution\SocialPublisherRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);
});

it('redirects to meta oauth for instagram connect', function (): void {
    [$user, $workspace] = instagramMvpUser();

    config([
        'services.meta.enabled' => true,
        'services.meta.client_id' => 'meta-client',
        'services.meta.client_secret' => 'meta-secret',
        'services.meta.redirect_uri' => 'https://app.example.test/settings/integrations/instagram/callback',
        'services.meta.graph_api_version' => 'v23.0',
    ]);

    $response = $this->actingAs($user)->get(route('app.settings.integrations.instagram.connect', ['workspace_id' => $workspace->id]));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('facebook.com/v23.0/dialog/oauth')
        ->toContain('instagram_basic')
        ->toContain('instagram_content_publish');
});

it('stores instagram business and creator accounts on callback', function (string $accountType): void {
    [$user, $workspace] = instagramMvpUser();

    config([
        'services.meta.enabled' => true,
        'services.meta.client_id' => 'meta-client',
        'services.meta.client_secret' => 'meta-secret',
        'services.meta.redirect_uri' => 'https://app.example.test/settings/integrations/instagram/callback',
        'services.meta.graph_api_version' => 'v23.0',
    ]);

    Http::fake([
        'graph.facebook.com/v23.0/oauth/access_token*' => Http::sequence()
            ->push(['access_token' => 'short-token'])
            ->push(['access_token' => 'long-token', 'expires_in' => 3600]),
        'graph.facebook.com/v23.0/me/accounts*' => Http::response([
            'data' => [[
                'id' => 'page-1',
                'name' => 'Meta Page',
                'access_token' => 'page-token',
                'instagram_business_account' => [
                    'id' => '17890000000000000',
                    'username' => 'argusly',
                    'name' => 'Argusly',
                    'account_type' => strtoupper($accountType),
                    'profile_picture_url' => 'https://example.test/avatar.jpg',
                ],
            ]],
        ]),
    ]);

    $this->actingAs($user)
        ->withSession([
            'instagram_oauth_state' => 'state-123',
            'instagram_oauth_workspace_id' => (string) $workspace->id,
        ])
        ->get(route('app.settings.integrations.instagram.callback', [
            'state' => 'state-123',
            'code' => 'auth-code',
        ]))
        ->assertRedirect(route('app.settings.integrations.instagram', ['workspace_id' => $workspace->id]))
        ->assertSessionHas('status', 'Instagram connected.');

    $account = SocialAccount::query()->where('provider', SocialPlatform::INSTAGRAM->value)->firstOrFail();

    expect($account->platform)->toBe(SocialPlatform::INSTAGRAM)
        ->and($account->account_type)->toBe($accountType)
        ->and($account->access_token)->toBe('long-token')
        ->and($account->isConnected())->toBeTrue();
})->with(['business', 'creator']);

it('rejects personal instagram accounts', function (): void {
    [$user, $workspace] = instagramMvpUser();

    config([
        'services.meta.enabled' => true,
        'services.meta.client_id' => 'meta-client',
        'services.meta.client_secret' => 'meta-secret',
        'services.meta.redirect_uri' => 'https://app.example.test/settings/integrations/instagram/callback',
        'services.meta.graph_api_version' => 'v23.0',
    ]);

    Http::fake([
        'graph.facebook.com/v23.0/oauth/access_token*' => Http::sequence()
            ->push(['access_token' => 'short-token'])
            ->push(['access_token' => 'long-token']),
        'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => []]),
    ]);

    $this->actingAs($user)
        ->withSession([
            'instagram_oauth_state' => 'state-123',
            'instagram_oauth_workspace_id' => (string) $workspace->id,
        ])
        ->get(route('app.settings.integrations.instagram.callback', [
            'state' => 'state-123',
            'code' => 'auth-code',
        ]))
        ->assertRedirect(route('app.settings.integrations.instagram', ['workspace_id' => $workspace->id]))
        ->assertSessionHasErrors('instagram');

    expect(SocialAccount::query()->where('provider', SocialPlatform::INSTAGRAM->value)->exists())->toBeFalse();
});

it('uses instagram renderer for instagram variants', function (): void {
    $variant = SocialPostVariant::factory()->make([
        'platform' => SocialPlatform::INSTAGRAM,
        'body' => 'Short visual caption.',
        'hashtags' => ['Argusly', '#AIVisibility'],
        'generation_prompt_context' => ['source_url' => 'https://example.test/article'],
    ]);

    expect($variant->publishingText())
        ->toBe(app(InstagramPostTextRenderer::class)->render('Short visual caption.', ['Argusly', '#AIVisibility']))
        ->not->toContain('https://example.test/article');
});

it('fails instagram publishing without media and publishes with a single image', function (): void {
    config(['services.meta.enabled' => true]);

    [$publication] = instagramPublication(mediaRefs: []);

    (new PublishSocialPostJob((string) $publication->id))->handle(
        app(SocialPublisherRegistry::class),
        app(SocialDistributionAuditLogger::class),
    );

    expect($publication->refresh()->status)->toBe(SocialPublicationStatus::FAILED)
        ->and($publication->last_error_code)->toBe('PUBLICATION_NOT_READY')
        ->and($publication->last_error_message)->toBe('Instagram posts require an image before publishing.');

    [$publicationWithMedia] = instagramPublication(mediaRefs: [['type' => 'image', 'url' => 'https://example.test/image.jpg']]);

    Http::fake([
        'graph.facebook.com/v23.0/*/media' => Http::response(['id' => 'container-1']),
        'graph.facebook.com/v23.0/*/media_publish' => Http::response(['id' => 'ig-post-1']),
    ]);

    (new PublishSocialPostJob((string) $publicationWithMedia->id))->handle(
        app(SocialPublisherRegistry::class),
        app(SocialDistributionAuditLogger::class),
    );

    expect($publicationWithMedia->refresh()->status)->toBe(SocialPublicationStatus::PUBLISHED)
        ->and($publicationWithMedia->remote_post_id)->toBe('ig-post-1')
        ->and(data_get($publicationWithMedia->response_snapshot, 'image_url'))->toBe('https://example.test/image.jpg');
});

it('creates linkedin and instagram variants from campaign assets', function (): void {
    [$user, $workspace] = instagramMvpUser();

    $campaign = Campaign::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'name' => 'Social Channel Campaign',
        'slug' => 'social-channel-campaign',
        'status' => 'planning',
        'metadata' => ['campaign_languages' => ['en']],
    ]);

    CampaignContent::query()->create([
        'campaign_id' => $campaign->id,
        'asset_type' => CampaignContentAssetType::LINKEDIN_POST->value,
        'status' => 'planned',
        'sequence_order' => 1,
        'working_title' => 'LinkedIn Post',
        'brief' => ['angle' => 'LinkedIn angle', 'audience_segment' => 'leaders'],
    ]);

    CampaignContent::query()->create([
        'campaign_id' => $campaign->id,
        'asset_type' => CampaignContentAssetType::INSTAGRAM_POST->value,
        'status' => 'planned',
        'sequence_order' => 2,
        'working_title' => 'Instagram Post',
        'brief' => ['angle' => 'Instagram angle', 'audience_segment' => 'teams'],
    ]);

    $summary = app(CampaignAssetGenerationService::class)->generate($campaign, $user);

    expect($summary['generated_social'])->toBe(2)
        ->and(SocialPostVariant::query()->where('campaign_id', $campaign->id)->where('platform', SocialPlatform::LINKEDIN->value)->exists())->toBeTrue()
        ->and(SocialPostVariant::query()->where('campaign_id', $campaign->id)->where('platform', SocialPlatform::INSTAGRAM->value)->exists())->toBeTrue()
        ->and(SocialPostVariant::query()->where('campaign_id', $campaign->id)->where('platform', SocialPlatform::INSTAGRAM->value)->firstOrFail()->publishingBlockedReason())->toBe('Instagram posts require an image before publishing.');
});

function instagramMvpUser(): array
{
    $organization = Organization::query()->create([
        'name' => 'Instagram MVP Org',
        'slug' => 'instagram-mvp-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Instagram MVP Workspace',
    ]);

    return [$user, $workspace, $organization];
}

function instagramPublication(array $mediaRefs): array
{
    [, $workspace, $organization] = instagramMvpUser();

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'provider' => SocialPlatform::INSTAGRAM->value,
        'platform' => SocialPlatform::INSTAGRAM,
        'account_type' => 'business',
        'display_name' => 'Argusly Instagram',
        'platform_account_id' => '17890000000000000',
        'access_token' => 'token',
        'status' => SocialAccountStatus::CONNECTED,
        'connected_at' => now(),
        'publishing_rules' => ['permissions' => ['draft', 'schedule', 'publish']],
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::INSTAGRAM,
        'post_type' => SocialPostType::IMAGE,
        'status' => SocialPostVariantStatus::APPROVED,
        'variant_number' => 1,
        'body' => 'A visual-first caption.',
        'hashtags' => ['#Argusly'],
        'media_refs' => $mediaRefs,
        'approved_at' => now(),
    ]);

    $publication = SocialPublication::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $variant->id,
        'platform' => SocialPlatform::INSTAGRAM,
        'status' => SocialPublicationStatus::QUEUED,
        'queued_at' => now(),
    ]);

    return [$publication, $variant, $account];
}
