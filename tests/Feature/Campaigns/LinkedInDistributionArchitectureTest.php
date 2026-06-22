<?php

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Enums\SocialPublicationStatus;
use App\Jobs\SocialDistribution\PublishSocialPostJob;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialDistributionAuditLog;
use App\Models\SocialEngagementMetric;
use App\Models\SocialPost;
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
    config(['services.linkedin.publishing_enabled' => false]);

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
        'social_distribution.linkedin_test_disclaimer_text' => 'Test vanuit Argusly: automatisch gegenereerd voor een Agentic Marketing Automation demo.',
    ]);

    $text = app(LinkedInPostTextRenderer::class)->render('Deze post is automatisch gemaakt.', hashtags: ['#Demo']);

    expect($text)->toStartWith('Test vanuit Argusly:')
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
        'social_distribution.linkedin_test_disclaimer_text' => 'Test vanuit Argusly: automatisch gegenereerd voor een Agentic Marketing Automation demo.',
    ]);

    $renderer = app(LinkedInPostTextRenderer::class);
    $once = $renderer->render('Test vanuit Argusly: automatisch gegenereerd voor een Agentic Marketing Automation demo.'."\n\n".'Body.');
    $twice = $renderer->render($once);

    expect(substr_count($twice, 'Test vanuit Argusly:'))->toBe(1);
});

it('uses the same linkedin renderer for preview snapshots and publishing', function (): void {
    config([
        'services.linkedin.enabled' => true,
        'services.linkedin.publishing_enabled' => true,
        'social_distribution.linkedin_test_disclaimer_enabled' => true,
        'social_distribution.linkedin_test_disclaimer_text' => 'Test vanuit Argusly: automatisch gegenereerd voor een Agentic Marketing Automation demo.',
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
        'hashtags' => ['#Demo', '#Argusly'],
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
        ->and($previewText)->toContain('#Demo #Argusly')
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
        "Een inzicht uit Agentic Marketing: De Nieuwe AI-Gestuurde Aanpak voor het Plannen, Uitvoeren en Optimaliseren van Campagnes:\n\nArgusly koppelt AI aan B2B-workflows.",
        'nl',
    );

    expect($review['hook'])->toBe('Agentic marketing: de nieuwe AI-gestuurde aanpak voor het plannen, uitvoeren en optimaliseren van campagnes')
        ->and($review['body'])->toContain('Een inzicht uit agentic marketing: de nieuwe AI-gestuurde aanpak voor het plannen, uitvoeren en optimaliseren van campagnes:')
        ->and($review['body'])->toContain('Argusly koppelt AI aan B2B-workflows.')
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

it('uses localized blog urls for dutch linkedin article links from content', function (): void {
    config(['app.url' => 'https://argusly.com']);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Localized URL Org',
        'slug' => 'linkedin-localized-url-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LinkedIn Localized URL Workspace',
    ]);

    $content = Content::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Nederlandse blog',
        'type' => 'article',
        'language' => 'nl',
        'status' => 'published',
        'publish_status' => 'published',
        'publish_url_key' => 'nederlandse-blog',
        'published_url' => 'https://argusly.com/blog/nederlandse-blog',
        'seo_canonical' => 'https://argusly.com/blog/nederlandse-blog',
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'content_id' => $content->id,
        'platform' => SocialPlatform::LINKEDIN->value,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP->value,
        'status' => SocialPostVariantStatus::DRAFT->value,
        'variant_number' => 1,
        'body' => 'Nederlandse distributietekst.',
        'hashtags' => ['#AIVisibility'],
        'generation_prompt_context' => [
            'language' => 'nl',
            'source_url' => 'https://argusly.com/blog/nederlandse-blog?utm_source=linkedin',
        ],
    ]);

    expect($variant->sourceUrl())->toBe('https://argusly.com/nl/blog/nederlandse-blog?utm_source=linkedin')
        ->and($variant->publishingText())->toContain('https://argusly.com/nl/blog/nederlandse-blog?utm_source=linkedin');
});

it('formats long target audience lists in dutch linkedin copy', function (): void {
    $organization = Organization::query()->create([
        'name' => 'LinkedIn Audience Formatting Org',
        'slug' => 'linkedin-audience-formatting-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LinkedIn Audience Formatting Workspace',
    ]);

    $content = Content::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Visibility answer engine optimization',
        'type' => 'article',
        'language' => 'nl',
        'public_blog_excerpt' => 'Een praktische manier om zichtbaarheid in AI-systemen te verbeteren.',
    ]);

    $post = app(\App\Actions\Social\GenerateLinkedInPostFromContent::class)->handle($content, [
        'language' => 'nl',
        'source_url' => 'https://argusly.com/nl/blog/visibility-answer-engine-optimization',
        'target_audience' => "Marketing Directors CMO's Growth Leaders Marketing Managers",
    ]);

    $body = (string) $post->variants
        ->firstWhere('variant_type', 'thought_leadership')
        ?->body;

    expect($body)->toContain("De nuttige verschuiving voor marketing directors, cmo's, growth leaders en marketing managers:")
        ->and($body)->not->toContain("Marketing Directors CMO's Growth Leaders Marketing Managers:");
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

it('renders campaignless published linkedin posts as posts in the timeline', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Campaignless Timeline Org',
        'slug' => 'linkedin-campaignless-timeline-'.Str::random(6),
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
        'name' => 'LinkedIn Campaignless Timeline Workspace',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'Campaignless Account',
        'status' => SocialAccountStatus::CONNECTED,
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::PUBLISHED,
        'variant_number' => 1,
        'hook' => 'Standalone LinkedIn post',
        'body' => 'This post was published without a campaign.',
        'approved_at' => now(),
    ]);

    SocialPublication::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $variant->id,
        'platform' => SocialPlatform::LINKEDIN,
        'status' => SocialPublicationStatus::PUBLISHED,
        'published_at' => now(),
        'remote_url' => 'https://www.linkedin.com/feed/update/urn:li:share:123',
        'payload_snapshot' => [
            'publishing_text' => 'Standalone LinkedIn post body.',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.distribution.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Standalone LinkedIn post')
        ->assertSee('Campaignless Account')
        ->assertSee('View on LinkedIn')
        ->assertDontSee('Unlinked campaign');
});

it('shows failed linkedin publication errors and allows retrying them', function (): void {
    config([
        'features.agentic_marketing' => true,
        'services.linkedin.publishing_enabled' => true,
    ]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Failed Retry Org',
        'slug' => 'linkedin-failed-retry-'.Str::random(6),
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
        'name' => 'LinkedIn Failed Retry Workspace',
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Failed Retry Campaign',
        'slug' => 'failed-retry-campaign',
        'status' => 'active',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'Retry Account',
        'status' => SocialAccountStatus::CONNECTED,
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'campaign_id' => $campaign->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::APPROVED,
        'variant_number' => 1,
        'hook' => 'Retry failed LinkedIn posts',
        'body' => 'Failed publications should explain what happened and remain retryable.',
        'approved_at' => now(),
        'approved_by' => $user->id,
    ]);

    SocialPublication::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $variant->id,
        'campaign_id' => $campaign->id,
        'platform' => SocialPlatform::LINKEDIN,
        'status' => SocialPublicationStatus::FAILED,
        'scheduled_for' => now()->subHour(),
        'last_error_code' => 'ACCOUNT_NOT_CONNECTED',
        'last_error_message' => 'LinkedIn account is not connected.',
    ]);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.distribution.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Failed Retry Campaign')
        ->assertSee('LinkedIn account is not connected.')
        ->assertSee('Publish now')
        ->assertDontSee('disabled');
});

it('approving a linkedin variant also approves the linked publish record', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'LinkedIn Approval Bridge Org',
        'slug' => 'linkedin-approval-bridge-'.Str::random(6),
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
        'name' => 'LinkedIn Approval Bridge Workspace',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'Approval Bridge Account',
        'status' => SocialAccountStatus::CONNECTED,
    ]);

    $post = SocialPost::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'provider' => SocialPlatform::LINKEDIN->value,
        'type' => 'article',
        'body' => 'Old draft body.',
        'url' => 'https://argusly.com/blog/stale-linkedin-url',
        'visibility' => 'public',
        'status' => 'draft',
        'error_message' => 'Human approval is required before publishing.',
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::DRAFT,
        'variant_number' => 1,
        'hook' => 'Approval bridge',
        'body' => 'Approving this variant should approve the linked publish record.',
    ]);

    $this->actingAs($user)
        ->post(route('app.agentic-marketing.distribution.variants.approve', [
            'variant' => $variant,
            'workspace_id' => $workspace->id,
        ]))
        ->assertRedirect();

    expect($variant->refresh()->status)->toBe(SocialPostVariantStatus::APPROVED)
        ->and($post->refresh()->status)->toBe('approved')
        ->and($post->error_message)->toBeNull()
        ->and($post->body)->toContain('Approving this variant should approve the linked publish record.');
});

it('repairs stale draft social posts before linkedin publication', function (): void {
    $organization = Organization::query()->create([
        'name' => 'LinkedIn Publication Repair Org',
        'slug' => 'linkedin-publication-repair-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LinkedIn Publication Repair Workspace',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'Publication Repair Account',
        'status' => SocialAccountStatus::CONNECTED,
    ]);

    $post = SocialPost::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'provider' => SocialPlatform::LINKEDIN->value,
        'type' => 'text',
        'body' => 'Old draft body.',
        'visibility' => 'public',
        'status' => 'draft',
        'error_message' => 'Human approval is required before publishing.',
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::APPROVED,
        'variant_number' => 1,
        'hook' => 'Publication repair',
        'body' => 'Retrying an approved variant should repair the linked draft post.',
        'generation_prompt_context' => [
            'source_url' => 'https://argusly.com/nl/blog/fixed-linkedin-url',
        ],
        'approved_at' => now(),
    ]);

    $publication = SocialPublication::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $variant->id,
        'platform' => SocialPlatform::LINKEDIN,
        'status' => SocialPublicationStatus::QUEUED,
        'scheduled_for' => now()->subMinute(),
    ]);

    $publisher = app(\App\Services\SocialDistribution\Publishers\LinkedInPublisher::class);
    $method = new ReflectionMethod($publisher, 'socialPostFor');
    $method->setAccessible(true);
    $resolvedPost = $method->invoke($publisher, $publication->fresh(['socialAccount', 'variant']));

    expect($resolvedPost)->toBeInstanceOf(SocialPost::class)
        ->and($post->refresh()->status)->toBe('approved')
        ->and($post->error_message)->toBeNull()
        ->and($post->body)->toContain('Retrying an approved variant should repair the linked draft post.')
        ->and($post->url)->toBe('https://argusly.com/nl/blog/fixed-linkedin-url')
        ->and($post->type)->toBe('article');
});

it('hides published linkedin variants from active work while keeping publication history visible', function (): void {
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
        'body' => 'Already-published LinkedIn copy should stay visible in history.',
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
        ->assertSee('Already-published LinkedIn copy should stay visible in history.')
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
