<?php

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Enums\SocialPublicationStatus;
use App\Jobs\SocialDistribution\PublishSocialPostJob;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialDistributionAuditLog;
use App\Models\SocialEngagementMetric;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use App\Services\Social\SocialPostService;
use App\Services\SocialDistribution\LinkedInPostTextRenderer;
use App\Services\SocialDistribution\Publishers\LinkedInPublisher as DistributionLinkedInPublisher;
use App\Services\SocialDistribution\SocialPostVariantGenerationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('models linkedin distribution as queue driven social publishing with failure audit state', function (): void {
    $organization = Organization::query()->create([
        'name' => 'LinkedIn Distribution Org',
        'slug' => 'linkedin-distribution-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LinkedIn Distribution Workspace',
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'LinkedIn Launch Campaign',
        'slug' => 'linkedin-launch-campaign',
        'status' => 'active',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'organization',
        'display_name' => 'Company LinkedIn',
        'platform_account_id' => 'urn:li:organization:123',
        'status' => SocialAccountStatus::CONNECTED,
        'connected_at' => now(),
        'oauth' => ['status' => 'placeholder'],
        'rate_limit_policy' => ['bucket' => 'publish'],
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'campaign_id' => $campaign->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::TECHNICAL_DEEP_DIVE,
        'status' => SocialPostVariantStatus::APPROVED,
        'variant_number' => 1,
        'hook' => 'A technical angle for agentic marketing.',
        'body' => 'Queue driven orchestration keeps publishing controllable.',
        'approved_at' => now(),
    ]);

    $publication = SocialPublication::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $variant->id,
        'campaign_id' => $campaign->id,
        'platform' => SocialPlatform::LINKEDIN,
        'status' => SocialPublicationStatus::QUEUED,
        'scheduled_for' => now()->subMinute(),
        'queued_at' => now(),
    ]);

    (new PublishSocialPostJob((string) $publication->id))->handle(
        app(\App\Services\SocialDistribution\SocialPublisherRegistry::class),
        app(\App\Services\SocialDistribution\SocialDistributionAuditLogger::class),
    );

    $publication->refresh();

    expect($publication->status)->toBe(SocialPublicationStatus::FAILED)
        ->and($publication->attempts)->toBe(1)
        ->and($publication->last_error_code)->toBe('PUBLISHING_DISABLED')
        ->and(SocialDistributionAuditLog::query()->where('social_publication_id', $publication->id)->count())->toBeGreaterThan(0);
});

it('renders failed linkedin generation as a setup state and blocks empty approvals', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn UI Org',
        'slug' => 'linkedin-ui-'.Str::random(6),
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
        'name' => 'LinkedIn UI Workspace',
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Agentic Marketing Campaign',
        'slug' => 'agentic-marketing-campaign',
        'status' => 'active',
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'campaign_id' => $campaign->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::FAILED,
        'variant_number' => 1,
        'generation_result' => [
            'error_code' => 'AI_GENERATION_PROVIDER_NOT_CONFIGURED',
            'message' => 'Configure a generation provider before producing copy.',
        ],
    ]);

    SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'campaign_id' => $campaign->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::FAILED,
        'variant_number' => 2,
        'generation_result' => [
            'error_code' => 'AI_GENERATION_FAILED',
            'message' => 'Array to string conversion',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.distribution.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('LinkedIn Accounts')
        ->assertSee('Provider setup required')
        ->assertSee('Generation provider required')
        ->assertSee('Configure a generation provider before producing copy.')
        ->assertSee('older campaign context')
        ->assertDontSee('Array to string conversion');

    $this->actingAs($user)
        ->post(route('app.agentic-marketing.distribution.variants.approve', $variant))
        ->assertSessionHasErrors('variant');

    expect($variant->refresh()->status)->toBe(SocialPostVariantStatus::FAILED);
});

it('generates linkedin variants through the configured llm provider', function (): void {
    $organization = Organization::query()->create([
        'name' => 'LinkedIn Provider Org',
        'slug' => 'linkedin-provider-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LinkedIn Provider Workspace',
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Agentic Marketing Campaign',
        'slug' => 'agentic-marketing-provider-campaign',
        'status' => 'active',
        'objective' => 'Explain agentic marketing operations.',
        'internal_linking_strategy' => [
            'pillar' => 'approval gates',
            'supporting_assets' => ['linkedin_post', 'implementation_guide'],
        ],
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'campaign_id' => $campaign->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::GENERATION_REQUESTED,
        'variant_number' => 1,
        'generation_prompt_context' => ['objective' => 'Explain the operating model.'],
    ]);

    $llm = Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')
        ->once()
        ->andReturn(new LlmResponse(
            text: '{"hook":"Frame the shift.","body":"Agentic marketing works when teams pair autonomy with approval gates.","hashtags":["AgenticMarketing"],"mentions":[],"quality_score":88}',
            json: [
                'hook' => 'Frame the shift.',
                'body' => 'Agentic marketing works when teams pair autonomy with approval gates.',
                'hashtags' => ['AgenticMarketing'],
                'mentions' => [],
                'quality_score' => 88,
            ],
            usage: new LlmUsage(120, 80, 200),
            modelUsed: 'gpt-4.1-mini',
            providerName: 'openai',
            requestId: 'req-social-variant',
        ));
    app()->instance(LlmManager::class, $llm);

    (new \App\Jobs\SocialDistribution\GenerateSocialPostVariantsJob([(string) $variant->id]))->handle(
        app(\App\Services\SocialDistribution\SocialDistributionAuditLogger::class),
        app(SocialPostVariantGenerationProvider::class),
    );

    $variant->refresh();

    expect($variant->status)->toBe(SocialPostVariantStatus::DRAFT)
        ->and($variant->hook)->toBe('Frame the shift.')
        ->and($variant->body)->toContain('approval gates')
        ->and($variant->publishingText())->not->toContain('Frame the shift.')
        ->and($variant->publishingText())->toContain('Agentic marketing works')
        ->and($variant->hashtags)->toBe(['#AgenticMarketing'])
        ->and($variant->quality_score)->toBe(88)
        ->and($variant->generation_model)->toBe('openai:gpt-4.1-mini')
        ->and(SocialDistributionAuditLog::query()->where('social_post_variant_id', $variant->id)->where('event', 'variant.generated')->exists())->toBeTrue();
});

it('adds the linkedin test disclaimer when enabled', function (): void {
    config([
        'social_distribution.linkedin_test_disclaimer_enabled' => true,
        'social_distribution.linkedin_test_disclaimer_text' => 'Test vanuit PublishLayer: automatisch gegenereerd voor een Agentic Marketing Automation demo.',
    ]);

    $text = app(LinkedInPostTextRenderer::class)->render('Deze post is automatisch gemaakt.', hashtags: ['#Demo']);

    expect($text)->toStartWith('Test vanuit PublishLayer:')
        ->and($text)->toContain('Deze post is automatisch gemaakt.')
        ->and($text)->toContain('#Demo');
});

it('does not add the linkedin test disclaimer when disabled', function (): void {
    config(['social_distribution.linkedin_test_disclaimer_enabled' => false]);

    $text = app(LinkedInPostTextRenderer::class)->render('Deze post is automatisch gemaakt.', hashtags: ['#Demo']);

    expect($text)->toBe("Deze post is automatisch gemaakt.\n\n#Demo");
});

it('does not duplicate the linkedin test disclaimer', function (): void {
    config([
        'social_distribution.linkedin_test_disclaimer_enabled' => true,
        'social_distribution.linkedin_test_disclaimer_text' => 'Test vanuit PublishLayer: automatisch gegenereerd voor een Agentic Marketing Automation demo.',
    ]);

    $renderer = app(LinkedInPostTextRenderer::class);
    $once = $renderer->render('Test vanuit PublishLayer: automatisch gegenereerd voor een Agentic Marketing Automation demo.'."\n\n".'Body.');
    $twice = $renderer->render($once);

    expect(substr_count($twice, 'Test vanuit PublishLayer:'))->toBe(1);
});

it('uses the same linkedin renderer for preview snapshots and publishing', function (): void {
    config([
        'services.linkedin.enabled' => true,
        'services.linkedin.publishing_enabled' => true,
        'social_distribution.linkedin_test_disclaimer_enabled' => true,
        'social_distribution.linkedin_test_disclaimer_text' => 'Test vanuit PublishLayer: automatisch gegenereerd voor een Agentic Marketing Automation demo.',
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Renderer Org',
        'slug' => 'linkedin-renderer-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LinkedIn Renderer Workspace',
    ]);
    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'organization',
        'display_name' => 'Company LinkedIn',
        'platform_account_id' => 'urn:li:organization:123',
        'provider_member_urn' => 'urn:li:person:123',
        'access_token' => 'token',
        'status' => SocialAccountStatus::CONNECTED,
        'connected_at' => now(),
    ]);
    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::TEXT,
        'status' => SocialPostVariantStatus::APPROVED,
        'variant_number' => 1,
        'body' => 'Preview en publish moeten gelijk zijn.',
        'hashtags' => ['#Demo', '#PublishLayer'],
        'generation_prompt_context' => ['source_url' => 'https://example.test/post'],
        'approved_at' => now(),
    ]);
    $renderer = app(LinkedInPostTextRenderer::class);
    $previewText = $renderer->renderVariant($variant);

    $publication = SocialPublication::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $variant->id,
        'platform' => SocialPlatform::LINKEDIN,
        'status' => SocialPublicationStatus::QUEUED,
        'payload_snapshot' => ['publishing_text' => $previewText],
    ]);

    $posts = Mockery::mock(SocialPostService::class);
    $posts->shouldReceive('publish')
        ->once()
        ->withArgs(fn ($post): bool => $post->body === $previewText)
        ->andReturnTrue();

    (new DistributionLinkedInPublisher($posts, $renderer))->publish($publication->fresh(['socialAccount', 'variant']));

    expect($variant->refresh()->socialPost->body)->toBe($previewText)
        ->and($previewText)->toContain('#Demo #PublishLayer')
        ->and($previewText)->toContain('https://example.test/post');
});

it('normalizes common dutch linkedin wording issues', function (): void {
    $organization = Organization::query()->create([
        'name' => 'LinkedIn Dutch Grammar Org',
        'slug' => 'linkedin-dutch-grammar-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LinkedIn Dutch Grammar Workspace',
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::GENERATION_REQUESTED,
        'variant_number' => 1,
        'generation_prompt_context' => ['language' => 'nl'],
    ]);

    $llm = Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')
        ->once()
        ->andReturn(new LlmResponse(
            text: '{"hook":"Nederlandse invalshoek","body":"Agentic marketing is geen nieuwe buzzword, maar een andere architectuur voor je marketingorganisatie.","hashtags":[],"mentions":[],"quality_score":80}',
            json: [
                'hook' => 'Nederlandse invalshoek',
                'body' => 'Agentic marketing is geen nieuwe buzzword, maar een andere architectuur voor je marketingorganisatie.',
                'hashtags' => [],
                'mentions' => [],
                'quality_score' => 80,
            ],
            usage: new LlmUsage(80, 40, 120),
            modelUsed: 'gpt-4.1-mini',
            providerName: 'openai',
            requestId: 'req-social-dutch-grammar',
        ));
    app()->instance(LlmManager::class, $llm);

    (new \App\Jobs\SocialDistribution\GenerateSocialPostVariantsJob([(string) $variant->id]))->handle(
        app(\App\Services\SocialDistribution\SocialDistributionAuditLogger::class),
        app(SocialPostVariantGenerationProvider::class),
    );

    expect($variant->refresh()->body)->toContain('geen nieuw buzzword')
        ->and($variant->body)->not->toContain('geen nieuwe buzzword')
        ->and(data_get($variant->generation_result, 'language_agent.language'))->toBe('nl')
        ->and(data_get($variant->generation_result, 'language_agent.changed'))->toBeTrue()
        ->and(data_get($variant->generation_result, 'language_agent.corrections'))->toContain('geen nieuwe buzzword -> geen nieuw buzzword');
});

it('normalizes dutch title case in generated linkedin copy', function (): void {
    $review = app(\App\Services\SocialDistribution\SocialCopyLanguageAgent::class)->review(
        'Agentic Marketing: De Nieuwe AI-Gestuurde Aanpak voor het Plannen, Uitvoeren en Optimaliseren van Campagnes',
        "Een inzicht uit Agentic Marketing: De Nieuwe AI-Gestuurde Aanpak voor het Plannen, Uitvoeren en Optimaliseren van Campagnes:\n\nPublishLayer koppelt AI aan B2B-workflows.",
        'nl',
    );

    expect($review['hook'])->toBe('Agentic marketing: de nieuwe AI-gestuurde aanpak voor het plannen, uitvoeren en optimaliseren van campagnes')
        ->and($review['body'])->toContain('Een inzicht uit agentic marketing: de nieuwe AI-gestuurde aanpak voor het plannen, uitvoeren en optimaliseren van campagnes:')
        ->and($review['body'])->toContain('PublishLayer koppelt AI aan B2B-workflows.')
        ->and($review['report']['changed'])->toBeTrue()
        ->and($review['report']['corrections'])->toContain('Dutch title case -> Dutch sentence case');
});

it('keeps language article links and requested hashtags with linkedin variants', function (): void {
    config(['features.agentic_marketing' => true]);
    \Illuminate\Support\Facades\Queue::fake();

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Language Org',
        'slug' => 'linkedin-language-'.Str::random(6),
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
        'name' => 'LinkedIn Language Workspace',
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Nederlandse Distributie',
        'slug' => 'nederlandse-distributie-'.Str::random(6),
        'status' => 'active',
        'objective' => 'Maak Nederlandstalige LinkedIn posts.',
    ]);

    $this->actingAs($user)
        ->post(route('app.agentic-marketing.distribution.variants.request', ['workspace_id' => $workspace->id]), [
            'campaign_id' => (string) $campaign->id,
            'post_type' => SocialPostType::THOUGHT_LEADERSHIP->value,
            'variant_count' => 1,
            'language' => 'nl',
            'source_url' => 'https://example.com/nl/artikel',
            'hashtags' => '#AIVisibility contentstrategie B2B',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'LinkedIn post variant generation queued.');

    $variant = SocialPostVariant::query()->where('campaign_id', $campaign->id)->firstOrFail();

    expect(data_get($variant->generation_prompt_context, 'language'))->toBe('nl')
        ->and(data_get($variant->generation_prompt_context, 'source_url'))->toBe('https://example.com/nl/artikel')
        ->and(data_get($variant->generation_prompt_context, 'hashtags'))->toBe(['#AIVisibility', '#contentstrategie', '#B2B']);

    $variant->forceFill([
        'status' => SocialPostVariantStatus::DRAFT,
        'body' => 'Dit is een Nederlandstalige LinkedIn-post.',
        'hashtags' => data_get($variant->generation_prompt_context, 'hashtags'),
    ])->save();

    expect($variant->publishingText())->toContain('Dit is een Nederlandstalige LinkedIn-post.')
        ->and($variant->publishingText())->toContain('https://example.com/nl/artikel')
        ->and($variant->publishingText())->toContain('#AIVisibility #contentstrategie #B2B');
});

it('lets users rename linkedin account placeholders from distribution', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Account Org',
        'slug' => 'linkedin-account-'.Str::random(6),
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
        'name' => 'LinkedIn Account Workspace',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'organization',
        'display_name' => 'Old LinkedIn Label',
        'status' => SocialAccountStatus::OAUTH_PENDING,
        'oauth' => ['status' => 'placeholder'],
        'publishing_rules' => ['approval_required' => true],
    ]);

    $this->actingAs($user)
        ->put(route('app.agentic-marketing.distribution.accounts.update', ['account' => $account, 'workspace_id' => $workspace->id]), [
            'display_name' => 'Founder LinkedIn',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'LinkedIn account name updated.');

    expect($account->refresh()->display_name)->toBe('Founder LinkedIn');
    expect(SocialDistributionAuditLog::query()->where('social_account_id', $account->id)->where('event', 'account.updated')->exists())->toBeTrue();
});

it('stores linkedin actor metadata and permissions on workspace social accounts', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Actor Org',
        'slug' => 'linkedin-actor-'.Str::random(6),
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
        'name' => 'LinkedIn Actor Workspace',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'Actor Account',
        'status' => SocialAccountStatus::CONNECTED,
        'publishing_rules' => ['permissions' => ['draft', 'schedule', 'publish']],
    ]);

    $this->actingAs($user)
        ->put(route('app.agentic-marketing.distribution.accounts.update', ['account' => $account, 'workspace_id' => $workspace->id]), [
            'display_name' => 'Ricardo Founder',
            'account_type' => 'person',
            'owner_user_id' => $user->id,
            'labels' => 'Founder, Vision',
            'tone_profile' => 'Strategic founder voice',
            'engagement_role' => 'primary_publisher',
            'approval_policy' => 'required',
            'can_schedule' => '1',
            'posting_limit_per_day' => '3',
        ])
        ->assertSessionHasNoErrors();

    $account->refresh();

    expect($account->labels())->toBe(['Founder', 'Vision'])
        ->and($account->toneProfile())->toBe('Strategic founder voice')
        ->and($account->engagementRole())->toBe('primary_publisher')
        ->and($account->user_id)->toBe($user->id)
        ->and($account->hasPermission('schedule'))->toBeTrue()
        ->and($account->hasPermission('publish'))->toBeFalse()
        ->and($account->isSchedulable())->toBeFalse()
        ->and(data_get($account->rate_limit_policy, 'posting_limit_per_day'))->toBe(3);
});

it('shows linkedin account profile photos with initials fallback', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Avatar Org',
        'slug' => 'linkedin-avatar-'.Str::random(6),
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
        'name' => 'LinkedIn Avatar Workspace',
    ]);

    SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'Ricardo Boekelmann-Hagens',
        'status' => SocialAccountStatus::ACTIVE,
        'profile' => ['picture' => 'https://media.licdn.com/avatar.jpg'],
    ]);

    SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'No Photo',
        'status' => SocialAccountStatus::OAUTH_PENDING,
        'profile' => [],
    ]);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.distribution.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('https://media.licdn.com/avatar.jpg')
        ->assertSee('NP');
});

it('renders the distribution publish queue when publications have engagement metrics', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Metrics Org',
        'slug' => 'linkedin-metrics-'.Str::random(6),
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
        'name' => 'LinkedIn Metrics Workspace',
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Metrics Campaign',
        'slug' => 'metrics-campaign',
        'status' => 'active',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'Metrics Account',
        'status' => SocialAccountStatus::CONNECTED,
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'campaign_id' => $campaign->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::SCHEDULED,
        'variant_number' => 1,
        'hook' => 'A measurable LinkedIn post',
        'body' => 'Publishing should keep working when metrics are present.',
    ]);

    $scheduledFor = now()->addDay()->setTime(14, 30);

    $publication = SocialPublication::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $variant->id,
        'campaign_id' => $campaign->id,
        'platform' => SocialPlatform::LINKEDIN,
        'status' => SocialPublicationStatus::SCHEDULED,
        'scheduled_for' => $scheduledFor,
    ]);

    SocialEngagementMetric::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_publication_id' => $publication->id,
        'platform' => SocialPlatform::LINKEDIN,
    ]);

    $scheduledLabel = $scheduledFor->copy()->timezone('Europe/Amsterdam')->format('d-m-Y H:i');

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.distribution.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Publish Queue')
        ->assertSee('Metrics Campaign')
        ->assertSee('Scheduled for '.$scheduledLabel)
        ->assertSee('Metrics Account');
});

it('hides published linkedin variants and publications from the active distribution workspace', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Published Cleanup Org',
        'slug' => 'linkedin-published-cleanup-'.Str::random(6),
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
        'name' => 'LinkedIn Published Cleanup Workspace',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'Published Cleanup Account',
        'status' => SocialAccountStatus::CONNECTED,
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Published Cleanup Campaign',
        'slug' => 'published-cleanup-campaign',
        'status' => 'active',
    ]);

    $publishedVariant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'campaign_id' => $campaign->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::SHORT_HOOK,
        'status' => SocialPostVariantStatus::PUBLISHED,
        'variant_number' => 1,
        'body' => 'Already-published LinkedIn copy should not be visible.',
    ]);

    SocialPublication::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $publishedVariant->id,
        'campaign_id' => $campaign->id,
        'platform' => SocialPlatform::LINKEDIN,
        'status' => SocialPublicationStatus::PUBLISHED,
        'scheduled_for' => now()->subDay(),
        'published_at' => now()->subDay(),
    ]);

    SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::SHORT_HOOK,
        'status' => SocialPostVariantStatus::DRAFT,
        'variant_number' => 2,
        'body' => 'Active draft LinkedIn copy should stay visible.',
    ]);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.distribution.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Active draft LinkedIn copy should stay visible.')
        ->assertSee('Published Cleanup Campaign')
        ->assertSee('Published')
        ->assertDontSee('Scheduled activity')
        ->assertDontSee('Already-published LinkedIn copy should not be visible.')
        ->assertSee('No publications queued.');
});

it('renders distribution interface copy in dutch when nl is selected', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Dutch UI Org',
        'slug' => 'linkedin-dutch-ui-'.Str::random(6),
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
        'name' => 'LinkedIn Dutch UI Workspace',
    ]);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.distribution.index', ['workspace_id' => $workspace->id, 'lang' => 'nl']))
        ->assertOk()
        ->assertSee('Distributie')
        ->assertSee('LinkedIn toevoegen')
        ->assertSee('LinkedIn-varianten genereren')
        ->assertSee('Maken vanuit content')
        ->assertSee('Taal')
        ->assertSee('Artikel-URL')
        ->assertSee('Generatie in wachtrij zetten')
        ->assertDontSee('Generate LinkedIn Variants');
});

it('lets users remove linkedin account placeholders from distribution', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Remove Org',
        'slug' => 'linkedin-remove-'.Str::random(6),
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
        'name' => 'LinkedIn Remove Workspace',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'organization',
        'display_name' => 'Remove Me',
        'status' => SocialAccountStatus::OAUTH_PENDING,
        'oauth' => ['status' => 'placeholder'],
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::SHORT_HOOK,
        'status' => SocialPostVariantStatus::DRAFT,
        'variant_number' => 1,
    ]);

    $this->actingAs($user)
        ->delete(route('app.agentic-marketing.distribution.accounts.destroy', ['account' => $account, 'workspace_id' => $workspace->id]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'LinkedIn account removed.');

    expect(SocialAccount::withTrashed()->find($account->id)?->trashed())->toBeTrue();
    expect($variant->refresh()->social_account_id)->toBeNull();
    expect(SocialDistributionAuditLog::query()->where('social_account_id', $account->id)->where('event', 'account.removed')->exists())->toBeTrue();
});

it('lets users unapprove and remove unscheduled linkedin variants', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Cleanup Org',
        'slug' => 'linkedin-cleanup-'.Str::random(6),
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
        'name' => 'LinkedIn Cleanup Workspace',
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::APPROVED,
        'variant_number' => 1,
        'body' => 'Approved copy that should be editable again.',
        'approved_at' => now(),
        'approved_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('app.agentic-marketing.distribution.variants.unapprove', $variant))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'LinkedIn variant moved back to draft.');

    $variant->refresh();

    expect($variant->status)->toBe(SocialPostVariantStatus::DRAFT)
        ->and($variant->approved_at)->toBeNull()
        ->and($variant->approved_by)->toBeNull()
        ->and(SocialDistributionAuditLog::query()->where('social_post_variant_id', $variant->id)->where('event', 'variant.approval_revoked')->exists())->toBeTrue();

    $this->actingAs($user)
        ->delete(route('app.agentic-marketing.distribution.variants.destroy', $variant))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'LinkedIn variant removed.');

    expect(SocialPostVariant::withTrashed()->find($variant->id)?->trashed())->toBeTrue();
    expect(SocialDistributionAuditLog::query()->where('social_post_variant_id', $variant->id)->where('event', 'variant.removed')->exists())->toBeTrue();
});
