<?php

use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\CreditAction;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Brief\BriefDefaultBuilder;
use App\Services\Brief\NormalizeContentBrief;
use App\Services\BriefProcessing\BriefToDraftService;
use App\Services\DraftGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('BriefDefaultBuilder', function () {
    it('builds valid brief structure from title only', function () {
        $builder = new BriefDefaultBuilder();
        $result = $builder->build('How to optimize WordPress performance');

        expect($result)->toHaveKey('intent')
            ->and($result['intent']['type'])->toBe('informational')
            ->and($result['intent']['keys'])->toBe(['How to optimize WordPress performance'])
            ->and($result['audience']['level'])->toBe('general')
            ->and($result['audience']['persona'])->toBe('website visitor')
            ->and($result['search_context']['stage'])->toBe('awareness')
            ->and($result['structure']['type'])->toBe('blog_article');
    });

    it('builds valid brief structure from title and keyword', function () {
        $builder = new BriefDefaultBuilder();
        $result = $builder->build('Ultimate Guide to SEO', 'seo optimization');

        expect($result['intent']['keys'])->toBe(['seo optimization'])
            ->and($result['topic']['title'])->toBe('Ultimate Guide to SEO')
            ->and($result['topic']['primary_keyword'])->toBe('seo optimization');
    });

    it('builds draft meta with all required fields', function () {
        $builder = new BriefDefaultBuilder();
        $result = $builder->buildDraftMeta('Content Marketing Tips', 'content marketing', 'en');

        expect($result)->toHaveKey('language')
            ->and($result['language'])->toBe('en')
            ->and($result['intent'])->toBe('informational')
            ->and($result['intent_keys'])->toBe(['content marketing'])
            ->and($result['primary_keyword'])->toBe('content marketing')
            ->and($result['audience'])->toBe('website visitor')
            ->and($result['funnel_stage'])->toBe('awareness')
            ->and($result['search_intent'])->toBe('informational')
            ->and($result['structure'])->toBeArray()
            ->and($result['structure'])->toContain('Opening');
    });

    it('detects incomplete brief data', function () {
        $builder = new BriefDefaultBuilder();

        $incomplete = ['title' => 'Test'];
        expect($builder->isComplete($incomplete))->toBeFalse();

        $complete = [
            'intent' => ['keys' => ['test keyword']],
            'audience' => 'business professionals',
        ];
        expect($builder->isComplete($complete))->toBeTrue();
    });

    it('merges defaults into incomplete brief data', function () {
        $builder = new BriefDefaultBuilder();

        $briefData = [
            'title' => 'Existing title',
            'audience' => 'developers', // Already has audience
        ];

        $merged = $builder->mergeDefaults($briefData, 'Test Title', 'test keyword');

        expect($merged['audience'])->toBe('developers') // Should keep existing
            ->and($merged['intent']['keys'])->toBe(['test keyword']) // Should add missing
            ->and($merged['structure'])->toBeArray(); // Should add missing
    });
});

describe('Draft generation from minimal content', function () {
    it('generates draft from content with only title', function () {
        Queue::fake();

        $context = createMinimalContentContext();
        $brief = $context['brief'];

        // Simulate draft creation via BriefToDraftService
        $service = app(BriefToDraftService::class);
        $draft = $service->claimAndCreateDraft((string) $brief->id);

        expect($draft)->not->toBeNull()
            ->and($draft->meta)->toBeArray()
            ->and(data_get($draft->meta, 'intent_keys'))->toBeArray()
            ->and(data_get($draft->meta, 'intent_keys'))->not->toBeEmpty()
            ->and(data_get($draft->meta, 'audience'))->not->toBeNull()
            ->and(data_get($draft->meta, 'structure'))->toBeArray();

        Queue::assertPushed(GenerateDraftJob::class);
    });

    it('populates missing fields in draft meta from BriefToDraftService', function () {
        Queue::fake();

        $context = createMinimalContentContext();
        $brief = $context['brief'];

        // Ensure brief has no intent_keys in client_refs
        $brief->client_refs = [];
        $brief->intent = null;
        $brief->audience = null;
        $brief->save();

        $service = app(BriefToDraftService::class);
        $draft = $service->claimAndCreateDraft((string) $brief->id);

        expect($draft)->not->toBeNull()
            ->and(data_get($draft->meta, 'intent_keys'))->not->toBeEmpty()
            ->and(data_get($draft->meta, 'audience'))->not->toBeNull()
            ->and(data_get($draft->meta, 'funnel_stage'))->not->toBeNull()
            ->and(data_get($draft->meta, 'search_intent'))->not->toBeNull()
            ->and(data_get($draft->meta, 'brief_defaults_applied'))->toBeTrue();
    });

    it('preserves existing brief fields when merging defaults', function () {
        Queue::fake();

        $context = createMinimalContentContext();
        $brief = $context['brief'];

        // Set specific values
        $brief->intent = 'transactional';
        $brief->audience = 'enterprise buyers';
        $brief->client_refs = [
            'taxonomy' => [
                'intent_keys' => ['buy software'],
            ],
        ];
        $brief->save();

        $service = app(BriefToDraftService::class);
        $draft = $service->claimAndCreateDraft((string) $brief->id);

        expect($draft)->not->toBeNull()
            ->and(data_get($draft->meta, 'intent'))->toBe('transactional')
            ->and(data_get($draft->meta, 'audience'))->toBe('enterprise buyers')
            ->and(data_get($draft->meta, 'intent_keys'))->toBe(['buy software']);
    });
});

describe('NormalizeContentBrief service', function () {
    it('applies defaults to incomplete draft meta', function () {
        $context = createMinimalContentContext();

        // Create draft with incomplete meta
        $draft = Draft::query()->create([
            'brief_id' => (string) $context['brief']->id,
            'content_id' => (string) $context['content']->id,
            'client_site_id' => (string) $context['site']->id,
            'status' => 'queued',
            'title' => 'Test Draft',
            'output_type' => 'kb_article',
            'meta' => [
                'language' => 'en',
                'primary_keyword' => 'test keyword',
                // Missing: intent_keys, audience, structure
            ],
        ]);

        // Use the NormalizeContentBrief service directly
        $normalizer = app(NormalizeContentBrief::class);
        $result = $normalizer->normalizeDraftMeta($draft);

        expect($result['normalized'])->toBeTrue()
            ->and($result['fields_added'])->toContain('intent_keys')
            ->and($result['fields_added'])->toContain('audience')
            ->and($result['fields_added'])->toContain('structure')
            ->and(data_get($result['meta'], 'intent_keys'))->not->toBeEmpty()
            ->and(data_get($result['meta'], 'audience'))->not->toBeNull()
            ->and(data_get($result['meta'], 'structure'))->toBeArray()
            ->and(data_get($result['meta'], '_normalized'))->toBeTrue()
            ->and(data_get($result['meta'], '_normalized_at'))->not->toBeNull();
    });

    it('does not modify draft meta when already complete', function () {
        $context = createMinimalContentContext();

        // Create draft with fully complete meta (all fields that normalizer checks)
        $completeMeta = [
            'language' => 'en',
            'primary_keyword' => 'test keyword',
            'intent_keys' => ['existing intent'],
            'intent' => 'transactional',
            'audience' => 'existing audience',
            'audience_tags' => ['tag1'],
            'funnel_stage' => 'consideration',
            'search_intent' => 'commercial',
            'structure' => ['Custom structure'],
            'content_type' => 'landing_page',
            'preferred_length' => 'long',
            'secondary_keywords' => ['secondary1'],
        ];

        $draft = Draft::query()->create([
            'brief_id' => (string) $context['brief']->id,
            'content_id' => (string) $context['content']->id,
            'client_site_id' => (string) $context['site']->id,
            'status' => 'queued',
            'title' => 'Test Draft',
            'output_type' => 'kb_article',
            'meta' => $completeMeta,
        ]);

        // Use the NormalizeContentBrief service directly
        $normalizer = app(NormalizeContentBrief::class);
        $result = $normalizer->normalizeDraftMeta($draft);

        expect($result['normalized'])->toBeFalse()
            ->and($result['fields_added'])->toBeEmpty()
            ->and(data_get($result['meta'], 'intent_keys'))->toBe(['existing intent'])
            ->and(data_get($result['meta'], 'audience'))->toBe('existing audience')
            ->and(data_get($result['meta'], 'structure'))->toBe(['Custom structure'])
            ->and(data_get($result['meta'], '_normalized'))->toBeNull();
    });

    it('validates draft for generation', function () {
        $context = createMinimalContentContext();

        // Create valid draft
        $draft = Draft::query()->create([
            'brief_id' => (string) $context['brief']->id,
            'content_id' => (string) $context['content']->id,
            'client_site_id' => (string) $context['site']->id,
            'status' => 'queued',
            'title' => 'Test Draft',
            'output_type' => 'kb_article',
            'credit_cost' => 4,
            'meta' => ['language' => 'en'],
        ]);

        $normalizer = app(NormalizeContentBrief::class);
        $validation = $normalizer->validateDraftForGeneration($draft);

        expect($validation['valid'])->toBeTrue()
            ->and($validation['missing'])->toBeEmpty()
            ->and($validation['errors'])->toBeEmpty();
    });

    it('fails validation when draft has no title', function () {
        $context = createMinimalContentContext();

        // Create draft without title
        $draft = Draft::query()->create([
            'brief_id' => (string) $context['brief']->id,
            'content_id' => (string) $context['content']->id,
            'client_site_id' => (string) $context['site']->id,
            'status' => 'queued',
            'title' => '',
            'output_type' => 'kb_article',
            'meta' => [],
        ]);

        $normalizer = app(NormalizeContentBrief::class);
        $validation = $normalizer->validateDraftForGeneration($draft);

        expect($validation['valid'])->toBeFalse()
            ->and($validation['missing'])->toContain('title')
            ->and($validation['errors'])->toContain('Draft has no title.');
    });

    it('auto-resolves missing credit_cost during validation', function () {
        $context = createMinimalContentContext();

        // Create draft without credit_cost
        $draft = Draft::query()->create([
            'brief_id' => (string) $context['brief']->id,
            'content_id' => (string) $context['content']->id,
            'client_site_id' => (string) $context['site']->id,
            'status' => 'queued',
            'title' => 'Test Draft',
            'output_type' => 'kb_article',
            'credit_cost' => 0,
            'meta' => ['language' => 'en'],
        ]);

        expect($draft->credit_cost)->toBe(0);

        $normalizer = app(NormalizeContentBrief::class);
        $validation = $normalizer->validateDraftForGeneration($draft);

        // Refresh to get auto-resolved value
        $draft->refresh();

        expect($validation['valid'])->toBeTrue()
            ->and($draft->credit_cost)->toBeGreaterThan(0);
    });
});

/**
 * Create a minimal content context for testing.
 *
 * @return array{organization: Organization, workspace: Workspace, site: ClientSite, user: User, content: Content, brief: Brief}
 */
function createMinimalContentContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Minimal Content Test Org',
        'slug' => 'minimal-content-test-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Test Site',
        'site_url' => 'https://test.example.test',
        'allowed_domains' => ['test.example.test'],
        'is_active' => true,
    ]);

    $user = User::query()->create([
        'name' => 'Test User',
        'email' => 'test-' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $content = Content::query()->create([
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Test Content Title',
        'primary_keyword' => 'test keyword',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
    ]);

    // Create brief with minimal fields (like "New Content" form)
    $brief = Brief::query()->create([
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Test Content Title',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'test keyword',
        // Intentionally missing: intent, audience, funnel_stage, search_intent
    ]);

    // Ensure a credit action exists for draft creation
    CreditAction::query()->firstOrCreate(
        ['key' => 'content.article'],
        [
            'name' => 'Article Generation',
            'label_en' => 'Article Generation',
            'label_nl' => 'Artikel Generatie',
            'category' => 'content',
            'credits_cost' => 1,
            'is_active' => true,
        ]
    );

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'site' => $site,
        'user' => $user,
        'content' => $content,
        'brief' => $brief,
    ];
}
