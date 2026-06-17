<?php

use App\Actions\Social\GenerateLinkedInPostFromContent;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use App\Models\SocialPublishAttempt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\SocialPostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('exposes the linkedin connect route with w_member_social scope', function (): void {
    [$user] = linkedinMvpUser();

    config([
        'services.linkedin.enabled' => true,
        'services.linkedin.client_id' => 'client-id',
        'services.linkedin.redirect_uri' => 'https://app.example.test/settings/integrations/linkedin/callback',
    ]);

    $response = $this->actingAs($user)->get(route('app.settings.integrations.linkedin.connect'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('openid')
        ->toContain('profile')
        ->toContain('w_member_social');
});

it('redirects back with setup guidance when linkedin oauth is disabled', function (): void {
    [$user, $workspace] = linkedinMvpUser();

    config(['services.linkedin.enabled' => false]);

    $this->actingAs($user)
        ->get(route('app.settings.integrations.linkedin.connect', ['workspace_id' => $workspace->id]))
        ->assertRedirect(route('app.settings.integrations.linkedin', ['workspace_id' => $workspace->id]))
        ->assertSessionHasErrors('linkedin');
});

it('stores an encrypted linkedin account on callback', function (): void {
    [$user, $workspace] = linkedinMvpUser();

    config([
        'services.linkedin.enabled' => true,
        'services.linkedin.client_id' => 'client-id',
        'services.linkedin.client_secret' => 'client-secret',
        'services.linkedin.redirect_uri' => 'https://app.example.test/settings/integrations/linkedin/callback',
    ]);

    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'plain-access-token',
            'expires_in' => 3600,
        ]),
        'api.linkedin.com/v2/userinfo' => Http::response([
            'sub' => 'abc123',
            'name' => 'Ada LinkedIn',
        ]),
    ]);

    $this->actingAs($user)
        ->withSession(['linkedin_oauth_state' => 'state-123'])
        ->get(route('app.settings.integrations.linkedin.callback', [
            'state' => 'state-123',
            'code' => 'auth-code',
            'workspace_id' => $workspace->id,
        ]))
        ->assertRedirect(route('app.settings.integrations.linkedin', ['workspace_id' => $workspace->id]));

    $account = SocialAccount::query()->firstOrFail();
    $rawToken = DB::table('social_accounts')->where('id', $account->id)->value('access_token');

    expect($account->provider_member_urn)->toBe('urn:li:person:abc123')
        ->and($account->access_token)->toBe('plain-access-token')
        ->and($rawToken)->not->toBe('plain-access-token');
});

it('redirects back when linkedin profile access is missing', function (): void {
    [$user, $workspace] = linkedinMvpUser();

    config([
        'services.linkedin.enabled' => true,
        'services.linkedin.client_id' => 'client-id',
        'services.linkedin.client_secret' => 'client-secret',
        'services.linkedin.redirect_uri' => 'https://app.example.test/settings/integrations/linkedin/callback',
    ]);

    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'plain-access-token',
            'expires_in' => 3600,
        ]),
        'api.linkedin.com/v2/userinfo' => Http::response([
            'code' => 'ACCESS_DENIED',
            'message' => 'Not enough permissions',
        ], 403),
    ]);

    $this->actingAs($user)
        ->withSession(['linkedin_oauth_state' => 'state-123'])
        ->get(route('app.settings.integrations.linkedin.callback', [
            'state' => 'state-123',
            'code' => 'auth-code',
            'workspace_id' => $workspace->id,
        ]))
        ->assertRedirect(route('app.settings.integrations.linkedin', ['workspace_id' => $workspace->id]))
        ->assertSessionHasErrors('linkedin');
});

it('redirects back when linkedin callback state is expired', function (): void {
    [$user, $workspace] = linkedinMvpUser();

    $this->actingAs($user)
        ->withSession(['linkedin_oauth_workspace_id' => (string) $workspace->id])
        ->get(route('app.settings.integrations.linkedin.callback', [
            'state' => 'returned-state',
            'code' => 'auth-code',
        ]))
        ->assertRedirect(route('app.settings.integrations.linkedin', ['workspace_id' => $workspace->id]))
        ->assertSessionHasErrors('linkedin');
});

it('creates a draft and variants from content without publishing', function (): void {
    [, $workspace] = linkedinMvpUser();
    $content = linkedinMvpContent($workspace, ['title' => 'Technical API Architecture']);

    $post = app(GenerateLinkedInPostFromContent::class)->handle($content, [
        'target_audience' => 'SaaS operators',
        'tone_of_voice' => 'practical',
    ]);

    expect($post->status)->toBe('draft')
        ->and($post->variants)->toHaveCount(5)
        ->and($post->variants->pluck('variant_type')->all())->toContain('technical_deep_dive');
});

it('can target a linkedin account when creating variants from content', function (): void {
    [, $workspace] = linkedinMvpUser();
    $content = linkedinMvpContent($workspace, [
        'title' => 'AI Visibility Playbook',
        'seo_meta_description' => 'A practical article for teams improving AI visibility.',
    ]);
    $account = linkedinMvpAccount($workspace);
    $account->forceFill([
        'profile' => [
            'labels' => ['Founder'],
            'tone_profile' => 'sharp founder voice',
            'engagement_role' => 'primary_publisher',
        ],
    ])->save();

    $post = app(GenerateLinkedInPostFromContent::class)->handle($content, [
        'social_account' => $account,
        'language' => 'nl',
    ]);

    expect((string) $post->social_account_id)->toBe((string) $account->id)
        ->and(data_get($post->metadata, 'target_social_account.display_name'))->toBe('Ada LinkedIn')
        ->and($post->variants)->toHaveCount(4);

    $post->variants->each(function ($variant) use ($account): void {
        expect((string) $variant->social_account_id)->toBe((string) $account->id)
            ->and(data_get($variant->generation_prompt_context, 'target_social_account.tone_profile'))->toBe('sharp founder voice')
            ->and(data_get($variant->generation_prompt_context, 'target_social_account.engagement_role'))->toBe('primary_publisher');
    });
});

it('adds campaign tracking parameters to linkedin article links', function (): void {
    [, $workspace] = linkedinMvpUser();
    $content = linkedinMvpContent($workspace, [
        'title' => 'AI Visibility Tracking',
        'seo_canonical' => 'https://example.com/blog/ai-visibility?existing=1',
    ]);
    $campaign = Campaign::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'name' => 'AI Visibility Campaign',
        'slug' => 'ai-visibility-campaign',
        'status' => 'active',
        'metadata' => [
            'tracking_parameters' => [
                'utm_source' => 'linkedin',
                'utm_medium' => 'social',
                'utm_campaign' => 'ai_visibility_campaign',
                'utm_content' => 'article_post',
            ],
        ],
    ]);

    $post = app(GenerateLinkedInPostFromContent::class)->handle($content, [
        'campaign' => $campaign,
        'language' => 'en',
    ]);

    $trackedUrl = 'https://example.com/blog/ai-visibility?existing=1&utm_source=linkedin&utm_medium=social&utm_campaign=ai_visibility_campaign&utm_content=article_post';

    expect($post->url)->toBe($trackedUrl)
        ->and($post->variants->first()->sourceUrl())->toBe($trackedUrl)
        ->and($post->variants->first()->publishingText())->toContain($trackedUrl);
});

it('adds standalone tracking parameters to linkedin article links without a campaign', function (): void {
    [, $workspace] = linkedinMvpUser();
    $content = linkedinMvpContent($workspace, [
        'title' => 'AI Visibility Tracking',
        'published_url' => 'https://example.com/nl/blog/ai-visibility?existing=1',
    ]);

    $post = app(GenerateLinkedInPostFromContent::class)->handle($content, [
        'language' => 'nl',
        'tracking_parameters' => [
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'ai_visibility_campaign',
            'utm_content' => 'short_hook',
        ],
    ]);

    $trackedUrl = 'https://example.com/nl/blog/ai-visibility?existing=1&utm_source=linkedin&utm_medium=social&utm_campaign=ai_visibility_campaign&utm_content=short_hook';

    expect($post->url)->toBe($trackedUrl)
        ->and($post->variants->first()->sourceUrl())->toBe($trackedUrl)
        ->and($post->variants->first()->publishingText())->toContain($trackedUrl);
});

it('keeps utm parameters from the content draft form visible in generated preview text', function (): void {
    [$user, $workspace] = linkedinMvpUser();
    $content = linkedinMvpContent($workspace, [
        'title' => 'Agentic marketing in Nederland',
        'language' => 'nl',
        'published_url' => 'https://argusly.com/nl/blog/agentic-marketing',
    ]);

    $this->actingAs($user)
        ->post(route('app.agentic-marketing.distribution.content-drafts.store', ['workspace_id' => $workspace->id]), [
            'content_id' => (string) $content->id,
            'language' => 'nl',
            'source_url' => 'https://argusly.com/nl/blog/agentic-marketing',
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'agentic_marketing_nl',
            'utm_content' => 'variant_2',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'LinkedIn draft created from content. Review and approve before scheduling.');

    $variant = SocialPostVariant::query()->where('content_id', $content->id)->latest()->firstOrFail();
    $trackedUrl = 'https://argusly.com/nl/blog/agentic-marketing?utm_source=linkedin&utm_medium=social&utm_campaign=agentic_marketing_nl&utm_content=variant_2';

    expect($variant->campaign_id)->toBeNull()
        ->and($variant->sourceUrl())->toBe($trackedUrl)
        ->and($variant->publishingText())->toContain($trackedUrl);
});

it('prefers the selected nl article published url over a stale english canonical', function (): void {
    [, $workspace] = linkedinMvpUser();
    $content = linkedinMvpContent($workspace, [
        'title' => 'Agentic marketing in Nederland',
        'language' => 'nl',
        'published_url' => 'https://argusly.com/nl/blog/agentic-marketing-in-nederland',
        'seo_canonical' => 'https://argusly.com/blog/agentic-marketing-defining-the-future-of-marketing-automation-and-autonomy',
    ]);

    $post = app(GenerateLinkedInPostFromContent::class)->handle($content, [
        'language' => 'nl',
    ]);

    expect($post->url)->toBe('https://argusly.com/nl/blog/agentic-marketing-in-nederland')
        ->and(data_get($post->metadata, 'source_url'))->toBe('https://argusly.com/nl/blog/agentic-marketing-in-nederland')
        ->and($post->variants->first()->sourceUrl())->toBe('https://argusly.com/nl/blog/agentic-marketing-in-nederland')
        ->and($post->variants->first()->publishingText())->toContain('https://argusly.com/nl/blog/agentic-marketing-in-nederland');
});

it('uses the localized content variant URL for the requested LinkedIn language', function (): void {
    [, $workspace] = linkedinMvpUser();
    $source = linkedinMvpContent($workspace, [
        'title' => 'Agentic marketing: defining the future',
        'language' => 'en',
        'published_url' => 'https://argusly.com/en/blog/agentic-marketing-defining-the-future',
        'seo_canonical' => 'https://argusly.com/en/blog/agentic-marketing-defining-the-future',
    ]);
    $source->forceFill([
        'family_id' => $source->id,
        'is_source_locale' => true,
    ])->save();

    $localized = linkedinMvpContent($workspace, [
        'title' => 'Agentic marketing: de toekomst definieren',
        'language' => 'nl',
        'family_id' => $source->id,
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'published_url' => 'https://argusly.com/nl/blog/agentic-marketing-de-toekomst-definieren',
        'seo_canonical' => 'https://argusly.com/nl/blog/agentic-marketing-de-toekomst-definieren',
    ]);

    $post = app(GenerateLinkedInPostFromContent::class)->handle($source, [
        'language' => 'nl',
    ]);

    expect((string) $post->content_id)->toBe((string) $localized->id)
        ->and($post->url)->toBe('https://argusly.com/nl/blog/agentic-marketing-de-toekomst-definieren')
        ->and(data_get($post->variants->first()->generation_prompt_context, 'source_content_id'))->toBe((string) $source->id)
        ->and(data_get($post->variants->first()->generation_prompt_context, 'resolved_content_id'))->toBe((string) $localized->id);
});

it('does not publish without approval', function (): void {
    [, $workspace] = linkedinMvpUser();
    $account = linkedinMvpAccount($workspace);
    $post = linkedinMvpPost($workspace, $account, ['status' => 'draft']);

    $published = app(SocialPostService::class)->publish($post);

    expect($published)->toBeFalse()
        ->and($post->refresh()->status)->toBe('failed')
        ->and($post->error_message)->toContain('approval');
});

it('does not publish when linkedin publishing is disabled', function (): void {
    [, $workspace] = linkedinMvpUser();
    $account = linkedinMvpAccount($workspace);
    $post = linkedinMvpPost($workspace, $account, ['status' => 'approved']);

    config(['services.linkedin.enabled' => true, 'services.linkedin.publishing_enabled' => false]);

    $published = app(SocialPostService::class)->publish($post);

    expect($published)->toBeFalse()
        ->and($post->refresh()->status)->toBe('failed')
        ->and(SocialPublishAttempt::query()->where('social_post_id', $post->id)->exists())->toBeTrue();
});

it('stores provider post id after successful text share', function (): void {
    [, $workspace] = linkedinMvpUser();
    $account = linkedinMvpAccount($workspace);
    $post = linkedinMvpPost($workspace, $account, ['status' => 'approved']);

    config(['services.linkedin.enabled' => true, 'services.linkedin.publishing_enabled' => true]);
    Http::fake([
        'api.linkedin.com/v2/ugcPosts' => Http::response([], 201, ['X-RestLi-Id' => 'urn:li:share:123']),
    ]);

    $published = app(SocialPostService::class)->publish($post);

    expect($published)->toBeTrue()
        ->and($post->refresh()->provider_post_id)->toBe('urn:li:share:123')
        ->and($post->status)->toBe('published');

    Http::assertSent(function ($request): bool {
        $content = $request->data()['specificContent']['com.linkedin.ugc.ShareContent'] ?? [];

        return $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
            && ($content['shareMediaCategory'] ?? null) === 'NONE';
    });
});

it('logs failed api responses as publish attempts', function (): void {
    [, $workspace] = linkedinMvpUser();
    $account = linkedinMvpAccount($workspace);
    $post = linkedinMvpPost($workspace, $account, ['status' => 'approved']);

    config(['services.linkedin.enabled' => true, 'services.linkedin.publishing_enabled' => true]);
    Http::fake([
        'api.linkedin.com/v2/ugcPosts' => Http::response(['message' => 'nope'], 422),
    ]);

    $published = app(SocialPostService::class)->publish($post);
    $attempt = SocialPublishAttempt::query()->where('social_post_id', $post->id)->firstOrFail();

    expect($published)->toBeFalse()
        ->and($post->refresh()->status)->toBe('failed')
        ->and($attempt->response_status)->toBe(422)
        ->and($attempt->error_message)->toBe('nope');
});

it('prevents duplicate publishes when provider id already exists', function (): void {
    [, $workspace] = linkedinMvpUser();
    $account = linkedinMvpAccount($workspace);
    $post = linkedinMvpPost($workspace, $account, [
        'status' => 'published',
        'provider_post_id' => 'urn:li:share:existing',
    ]);

    config(['services.linkedin.enabled' => true, 'services.linkedin.publishing_enabled' => true]);
    Http::fake();

    $published = app(SocialPostService::class)->publish($post);

    expect($published)->toBeTrue();
    Http::assertNothingSent();
});

/**
 * @return array{0:User,1:Workspace,2:Organization}
 */
function linkedinMvpUser(): array
{
    $organization = Organization::query()->create([
        'name' => 'LinkedIn MVP Org',
        'slug' => 'linkedin-mvp-'.Str::random(8),
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
        'name' => 'LinkedIn MVP Workspace',
    ]);

    return [$user, $workspace, $organization];
}

function linkedinMvpContent(Workspace $workspace, array $overrides = []): Content
{
    return Content::query()->create(array_merge([
        'workspace_id' => $workspace->id,
        'title' => 'LinkedIn Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
    ], $overrides));
}

function linkedinMvpAccount(Workspace $workspace): SocialAccount
{
    return SocialAccount::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'user_id' => User::query()->where('organization_id', $workspace->organization_id)->value('id'),
        'provider' => 'linkedin',
        'provider_member_urn' => 'urn:li:person:abc123',
        'access_token' => 'access-token',
        'scopes' => ['w_member_social'],
        'platform' => 'linkedin',
        'account_type' => 'person',
        'display_name' => 'Ada LinkedIn',
        'platform_account_id' => 'urn:li:person:abc123',
        'status' => 'active',
        'connected_at' => now(),
    ]);
}

function linkedinMvpPost(Workspace $workspace, SocialAccount $account, array $overrides = []): SocialPost
{
    return SocialPost::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'provider' => 'linkedin',
        'type' => 'text',
        'body' => 'A reviewed LinkedIn text share.',
        'visibility' => 'public',
        'status' => 'approved',
    ], $overrides));
}
