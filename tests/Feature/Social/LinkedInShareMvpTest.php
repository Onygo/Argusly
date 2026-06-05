<?php

use App\Actions\Social\GenerateLinkedInPostFromContent;
use App\Models\Content;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPost;
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
