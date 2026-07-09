<?php

namespace Tests\Feature\Translation;

use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentTranslation;
use App\Models\Draft;
use App\Models\MarketingBlogRedirect;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\HumanContent\HumanContentScoreService;
use App\Services\HumanContent\HumanizationService;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use App\Services\Translation\TranslationPromptBuilder;
use App\Services\Translation\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TranslationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TranslationService $service;
    protected User $user;
    protected Organization $organization;
    protected Workspace $workspace;
    protected ClientSite $clientSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TranslationService::class);

        $this->user = User::query()->create([
            'name' => 'Test User',
            'email' => 'test-' . Str::random(8) . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->organization = Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org-' . Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'primary_user_id' => $this->user->id,
        ]);

        $this->user->organization_id = $this->organization->id;
        $this->user->save();

        $this->workspace = Workspace::query()->create([
            'name' => 'Test Workspace',
            'organization_id' => $this->organization->id,
            'default_content_language' => SupportedLanguage::EN->value,
            'enabled_content_languages' => [
                SupportedLanguage::EN->value,
                SupportedLanguage::NL->value,
                SupportedLanguage::DE->value,
            ],
        ]);

        $this->clientSite = ClientSite::query()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'wordpress',
            'name' => 'Test Site',
            'site_url' => 'https://test.example.com',
            'allowed_domains' => ['test.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);
    }

    public function test_validate_source_draft_rejects_translation_drafts(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::TRANSLATION->value,
            'language' => SupportedLanguage::EN->value,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Translations must be created from original or hybrid drafts');

        $this->service->validateSourceDraft($sourceDraft);
    }

    public function test_validate_source_draft_accepts_original_drafts(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $this->service->validateSourceDraft($sourceDraft);
        $this->assertTrue(true);
    }

    public function test_validate_source_draft_accepts_hybrid_drafts(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::HYBRID->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $this->service->validateSourceDraft($sourceDraft);
        $this->assertTrue(true);
    }

    public function test_validate_source_draft_rejects_empty_content(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source draft has no content to translate');

        $this->service->validateSourceDraft($sourceDraft);
    }

    public function test_validate_source_draft_rejects_non_ready_status(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'processing',
            'content_html' => '<p>Test content</p>',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source content must be available as a ready draft or a delivered/published version');

        $this->service->validateSourceDraft($sourceDraft);
    }

    public function test_validate_target_language_rejects_same_language(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot translate draft to the same language');

        $this->service->validateTargetLanguage($sourceDraft, SupportedLanguage::EN);
    }

    public function test_validate_target_language_allows_nl_to_en(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::NL->value,
            'status' => 'ready',
            'content_html' => '<p>Nederlandse broncontent</p>',
        ]);

        $this->service->validateTargetLanguage($sourceDraft, SupportedLanguage::EN);
        $this->assertTrue(true);
    }

    public function test_validate_target_language_allows_en_to_nl(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>English source content</p>',
        ]);

        $this->service->validateTargetLanguage($sourceDraft, SupportedLanguage::NL);
        $this->assertTrue(true);
    }

    public function test_validate_target_language_uses_source_content_locale_when_legacy_draft_locale_is_stale(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'content_language' => SupportedLanguage::NL->value,
            'brief_language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Dit is Nederlandse broncontent.</p>',
            'meta' => [
                'language' => SupportedLanguage::EN->value,
                'source_version_meta' => [
                    'draft_meta' => [
                        'language' => SupportedLanguage::EN->value,
                    ],
                ],
            ],
        ]);

        $sourceDraft->content->forceFill([
            'is_source_locale' => true,
            'translation_source_locale' => SupportedLanguage::NL->value,
        ])->save();

        $this->service->validateTargetLanguage($sourceDraft->fresh(['content']), SupportedLanguage::EN);
        $this->assertTrue(true);
    }

    public function test_validate_target_language_rejects_disabled_language(): void
    {
        $this->workspace->enabled_content_languages = [SupportedLanguage::EN->value];
        $this->workspace->save();

        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not enabled for this workspace');

        $this->service->validateTargetLanguage($sourceDraft, SupportedLanguage::NL);
    }

    public function test_validate_target_language_rejects_duplicate_translation(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $this->createUsableTranslatedDraft($sourceDraft, SupportedLanguage::NL);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists for this draft');

        $this->service->validateTargetLanguage($sourceDraft, SupportedLanguage::NL);
    }

    public function test_validate_target_language_for_job_allows_own_processing_lock(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $jobUuid = (string) Str::uuid();
        $translation = ContentTranslation::query()->create([
            'content_id' => (string) $sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'processing_job_uuid' => $jobUuid,
            'processing_started_at' => now(),
            'processing_locked_at' => now(),
            'processing_last_heartbeat_at' => now(),
        ]);

        $this->service->validateTargetLanguageAvailabilityForJob(
            $sourceDraft,
            SupportedLanguage::NL,
            false,
            $jobUuid,
            (string) $translation->id
        );

        $this->assertTrue(true);
    }

    public function test_validate_target_language_for_job_blocks_other_fresh_processing_job(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        ContentTranslation::query()->create([
            'content_id' => (string) $sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'processing_job_uuid' => (string) Str::uuid(),
            'processing_started_at' => now(),
            'processing_locked_at' => now(),
            'processing_last_heartbeat_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("A translation to 'Dutch' is already processing.");

        $this->service->validateTargetLanguageAvailabilityForJob(
            $sourceDraft,
            SupportedLanguage::NL,
            false,
            (string) Str::uuid(),
            (string) Str::uuid()
        );
    }

    public function test_validate_target_language_for_job_blocks_existing_target_content_without_exact_owned_target(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $this->createUsableTranslatedDraft($sourceDraft, SupportedLanguage::NL);

        $translation = ContentTranslation::query()->create([
            'content_id' => (string) $sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'processing_job_uuid' => (string) Str::uuid(),
            'processing_started_at' => now(),
            'processing_locked_at' => now(),
            'processing_last_heartbeat_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("A translation to 'Dutch' already exists for this draft.");

        $this->service->validateTargetLanguageAvailabilityForJob(
            $sourceDraft,
            SupportedLanguage::NL,
            true,
            (string) $translation->processing_job_uuid,
            (string) $translation->id
        );
    }

    public function test_validate_target_language_for_dispatch_allows_existing_linked_target_when_existing_is_allowed(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $this->createUsableTranslatedDraft($sourceDraft, SupportedLanguage::NL);

        $this->service->validateTargetLanguageAvailabilityForDispatch(
            $sourceDraft,
            SupportedLanguage::NL,
            true
        );

        $this->assertTrue(true);
    }

    public function test_validate_target_language_for_job_allows_existing_target_content_for_exact_owned_target(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $translatedDraft = $this->createUsableTranslatedDraft($sourceDraft, SupportedLanguage::NL);
        $jobUuid = (string) Str::uuid();

        $translation = ContentTranslation::query()->create([
            'content_id' => (string) $sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'target_content_id' => (string) $translatedDraft->content_id,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'processing_job_uuid' => $jobUuid,
            'processing_started_at' => now(),
            'processing_locked_at' => now(),
            'processing_last_heartbeat_at' => now(),
        ]);

        $this->service->validateTargetLanguageAvailabilityForJob(
            $sourceDraft,
            SupportedLanguage::NL,
            true,
            $jobUuid,
            (string) $translation->id
        );

        $this->assertTrue(true);
    }

    public function test_translate_uses_job_aware_validation_context_for_owned_processing_request(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $jobUuid = (string) Str::uuid();
        $translation = ContentTranslation::query()->create([
            'content_id' => (string) $sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'processing_job_uuid' => $jobUuid,
            'processing_started_at' => now(),
            'processing_locked_at' => now(),
            'processing_last_heartbeat_at' => now(),
        ]);

        $llmManager = \Mockery::mock(LlmManager::class);
        $llmManager->shouldReceive('generateJson')
            ->once()
            ->andThrow(new RuntimeException('provider reached'));
        $this->app->instance(LlmManager::class, $llmManager);

        $service = $this->app->make(TranslationService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider reached');

        $service->translate(
            $sourceDraft,
            SupportedLanguage::NL,
            null,
            false,
            [
                'job_uuid' => $jobUuid,
                'translation_request_id' => (string) $translation->id,
            ]
        );
    }

    public function test_translation_prompt_allows_editorial_naturalization_in_target_language(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'title' => 'Why content approval matters',
            'content_html' => '<h1>Introduction</h1><p>In today\'s digital landscape, approval speed is important.</p>',
        ]);

        $systemPrompt = app(TranslationPromptBuilder::class)->buildSystemPrompt(SupportedLanguage::NL);
        $userPrompt = app(TranslationPromptBuilder::class)->buildUserPrompt($sourceDraft, SupportedLanguage::EN, SupportedLanguage::NL);

        $this->assertStringContainsString('You may improve headings, sentence rhythm, paragraph flow, local idiom', $systemPrompt);
        $this->assertStringContainsString('Do not preserve AI-like rhythm', $systemPrompt);
        $this->assertStringContainsString('When translating Dutch "Van X naar Y" titles', $systemPrompt);
        $this->assertStringContainsString('Improve heading naturalness, sentence rhythm, paragraph flow', $userPrompt);
        $this->assertStringContainsString('write "From X to Y"', $userPrompt);
        $this->assertStringNotContainsString('Preserve the exact HTML structure', $userPrompt);
    }

    public function test_translate_normalizes_dutch_van_naar_connectors_in_english_titles(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::NL->value,
            'status' => 'ready',
            'title' => 'Van AI-chatbot naar AI-orchestratie',
            'content_html' => '<h1>Van AI-chatbot naar AI-orchestratie</h1><p>Nederlandse broncontent met een duidelijke route.</p>',
        ]);

        $llmManager = \Mockery::mock(LlmManager::class);
        $llmManager->shouldReceive('generateJson')
            ->once()
            ->andReturn(new LlmResponse(
                text: '',
                json: [
                    'title' => 'Van AI Chatbot to AI Orchestration: A Practical Decision Framework',
                    'content_html' => '<h1>Van AI Chatbot to AI Orchestration</h1><p>English body text.</p>',
                    'seo' => [
                        'seo_title' => 'Van AI Chatbot to AI Orchestration',
                        'seo_meta_description' => 'Van AI chatbot to orchestration without losing governance.',
                        'seo_h1' => 'Van AI Chatbot to AI Orchestration',
                        'seo_og_title' => 'Van AI Chatbot to AI Orchestration',
                        'seo_og_description' => 'Van AI chatbot to orchestration without losing governance.',
                        'slug' => 'van-ai-chatbot-to-ai-orchestration',
                        'suggested_primary_keyword' => 'AI orchestration',
                        'secondary_keywords' => ['AI chatbot'],
                    ],
                ],
                usage: new LlmUsage(120, 180, 300),
                modelUsed: 'gpt-4.1-mini',
                providerName: 'test',
                requestId: 'req-translation-english-connectors',
            ));
        $this->app->instance(LlmManager::class, $llmManager);

        $result = $this->app->make(TranslationService::class)->translate($sourceDraft, SupportedLanguage::EN);

        $this->assertSame('From AI Chatbot to AI Orchestration: A Practical Decision Framework', $result['title']);
        $this->assertStringContainsString('<h1>From AI Chatbot to AI Orchestration</h1>', $result['content_html']);
        $this->assertSame('From AI Chatbot to AI Orchestration', $result['seo']['seo_title']);
        $this->assertSame('From AI chatbot to orchestration without losing governance.', $result['seo']['seo_meta_description']);
        $this->assertSame('From AI Chatbot to AI Orchestration', $result['seo']['seo_h1']);
    }

    public function test_translate_preserves_link_urls_in_translation_result(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'title' => 'Approval speed',
            'content_html' => '<h1>Approval speed</h1><p>Read the <a href="/en/blog/editorial-workflows">editorial workflow guide</a> before changing the CTA.</p>',
        ]);

        $llmManager = \Mockery::mock(LlmManager::class);
        $llmManager->shouldReceive('generateJson')
            ->once()
            ->andReturn(new LlmResponse(
                text: '',
                json: [
                    'title' => 'Goedkeuringssnelheid',
                    'content_html' => '<h1>Waarom goedkeuringssnelheid telt</h1><p>Lees de <a href="/en/blog/editorial-workflows">gids voor redactionele workflows</a> voordat je de CTA wijzigt.</p>',
                    'seo' => [
                        'seo_title' => 'Goedkeuringssnelheid',
                        'seo_meta_description' => 'Nederlandse beschrijving over goedkeuringssnelheid.',
                        'seo_h1' => 'Waarom goedkeuringssnelheid telt',
                        'slug' => 'goedkeuringssnelheid',
                        'suggested_primary_keyword' => 'goedkeuringssnelheid',
                        'secondary_keywords' => ['redactionele workflow'],
                    ],
                ],
                usage: new LlmUsage(120, 180, 300),
                modelUsed: 'gpt-4.1-mini',
                providerName: 'test',
                requestId: 'req-translation-links',
            ));
        $this->app->instance(LlmManager::class, $llmManager);

        $result = $this->app->make(TranslationService::class)->translate($sourceDraft, SupportedLanguage::NL);

        $this->assertStringContainsString('href="/en/blog/editorial-workflows"', $result['content_html']);
        $this->assertStringContainsString('Waarom goedkeuringssnelheid telt', $result['content_html']);
    }

    public function test_translate_rejects_results_that_drop_required_links(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'title' => 'Approval speed',
            'content_html' => '<p>Read the <a href="/en/blog/editorial-workflows">editorial workflow guide</a>.</p>',
        ]);

        $llmManager = \Mockery::mock(LlmManager::class);
        $llmManager->shouldReceive('generateJson')
            ->once()
            ->andReturn(new LlmResponse(
                text: '',
                json: [
                    'title' => 'Goedkeuringssnelheid',
                    'content_html' => '<p>Lees de gids voor redactionele workflows.</p>',
                    'seo' => ['slug' => 'goedkeuringssnelheid'],
                ],
                usage: new LlmUsage(80, 100, 180),
                modelUsed: 'gpt-4.1-mini',
                providerName: 'test',
            ));
        $this->app->instance(LlmManager::class, $llmManager);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Translation response removed or changed required link URLs');

        $this->app->make(TranslationService::class)->translate($sourceDraft, SupportedLanguage::NL);
    }

    public function test_validate_target_language_ignores_stale_translation_draft_without_valid_variant_even_with_legacy_redirect(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::NL->value,
            'status' => 'ready',
            'content_html' => '<p>Nederlandse broncontent</p>',
        ]);

        $sourceDraft->content->forceFill([
            'is_source_locale' => true,
            'translation_source_locale' => SupportedLanguage::NL->value,
            'publish_url_key' => 'legacy-nederlandse-bron',
        ])->save();

        MarketingBlogRedirect::query()->create([
            'source_path' => '/en/blog/legacy-nederlandse-bron',
            'source_locale' => 'en',
            'source_slug' => 'legacy-nederlandse-bron',
            'target_path' => '/nl/blog/legacy-nederlandse-bron',
            'target_locale' => 'nl',
            'target_slug' => 'legacy-nederlandse-bron',
            'target_content_id' => (string) $sourceDraft->content_id,
            'redirect_kind' => 'legacy_locale_mismatch',
            'is_active' => true,
        ]);

        $orphanBrief = Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => (string) $this->clientSite->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Orphan English translation brief',
            'language' => SupportedLanguage::EN->value,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
        ]);

        Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => (string) $orphanBrief->id,
            'content_id' => null,
            'client_site_id' => (string) $this->clientSite->id,
            'status' => 'ready',
            'title' => 'Stale orphan translation draft',
            'language' => SupportedLanguage::EN->value,
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => (string) $sourceDraft->id,
            'translation_source_language' => SupportedLanguage::NL->value,
            'output_type' => 'kb_article',
            'content_html' => '<p>Broken translation payload.</p>',
        ]);

        $this->service->validateTargetLanguage($sourceDraft->fresh(['content']), SupportedLanguage::EN);
        $this->assertTrue(true);
    }

    public function test_can_translate_to_languages_returns_available_languages(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $available = $this->service->canTranslateToLanguages($sourceDraft);

        $this->assertCount(2, $available);
        $this->assertContainsEquals(SupportedLanguage::NL, $available);
        $this->assertContainsEquals(SupportedLanguage::DE, $available);
        $this->assertNotContainsEquals(SupportedLanguage::EN, $available);
    }

    public function test_can_translate_to_languages_excludes_existing_translations(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $this->createUsableTranslatedDraft($sourceDraft, SupportedLanguage::NL);

        $available = $this->service->canTranslateToLanguages($sourceDraft);

        $this->assertCount(1, $available);
        $this->assertContainsEquals(SupportedLanguage::DE, $available);
        $this->assertNotContainsEquals(SupportedLanguage::NL, $available);
    }

    public function test_can_translate_to_languages_ignores_stale_translation_drafts_without_valid_variants(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::NL->value,
            'status' => 'ready',
            'content_html' => '<p>Test content</p>',
        ]);

        $orphanBrief = Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => (string) $this->clientSite->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Stale target brief',
            'language' => SupportedLanguage::EN->value,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
        ]);

        Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => (string) $orphanBrief->id,
            'content_id' => null,
            'client_site_id' => (string) $this->clientSite->id,
            'status' => 'ready',
            'title' => 'Broken EN translation draft',
            'language' => SupportedLanguage::EN->value,
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => (string) $sourceDraft->id,
            'translation_source_language' => SupportedLanguage::NL->value,
            'output_type' => 'kb_article',
            'content_html' => '<p>Broken translation payload.</p>',
        ]);

        $available = $this->service->canTranslateToLanguages($sourceDraft);

        $this->assertContainsEquals(SupportedLanguage::EN, $available);
    }

    public function test_estimate_translation_credits_returns_correct_cost(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'content_html' => '<p>Test content</p>',
        ]);

        $cost = $this->service->estimateTranslationCredits($sourceDraft);

        $this->assertGreaterThan(0, $cost);
        $this->assertLessThanOrEqual(10, $cost);
    }

    public function test_resolve_translation_model_returns_cheaper_model(): void
    {
        $model = $this->service->resolveTranslationModel();

        $this->assertNotEmpty($model);
        $this->assertStringContainsString('mini', strtolower($model));
    }

    public function test_create_translated_draft_creates_localized_content_and_brief_records(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<h1>Hello world</h1><p>Original content</p>',
            'seo_title' => 'Hello world',
            'seo_meta_description' => 'Original meta description',
        ]);

        $translatedDraft = $this->service->createTranslatedDraft(
            $sourceDraft,
            SupportedLanguage::NL,
            [
                'title' => 'Hallo wereld',
                'content_html' => '<h1>Hallo wereld</h1><p>Vertaald</p>',
                'seo' => [
                    'seo_title' => 'Hallo wereld',
                    'seo_meta_description' => 'Nederlandse meta beschrijving',
                    'seo_h1' => 'Hallo wereld',
                    'slug' => 'hallo-wereld',
                    'suggested_primary_keyword' => 'hallo wereld',
                    'secondary_keywords' => ['nederlandse vertaling'],
                ],
                'model_used' => 'gpt-4.1-mini',
                'input_tokens' => 120,
                'output_tokens' => 180,
                'total_tokens' => 300,
                'request_id' => 'req-translation-test',
            ],
            (string) $this->user->id
        );

        $this->assertNotSame((string) $sourceDraft->content_id, (string) $translatedDraft->content_id);
        $this->assertNotSame((string) $sourceDraft->brief_id, (string) $translatedDraft->brief_id);
        $this->assertSame(SupportedLanguage::NL, $translatedDraft->language);
        $this->assertSame(DraftType::TRANSLATION, $translatedDraft->draft_type);
        $this->assertSame((string) $sourceDraft->id, (string) $translatedDraft->source_draft_id);
        $this->assertSame('nl', (string) $translatedDraft->content->language->value);
        $this->assertSame('nl', (string) $translatedDraft->brief->language);
        $this->assertSame('hallo wereld', (string) $translatedDraft->content->primary_keyword);
        $this->assertSame('hallo-wereld', (string) data_get($translatedDraft->meta, 'translation.seo.slug'));
        $this->assertSame('hallo-wereld-nl', (string) $translatedDraft->content->external_key);
        $this->assertSame((string) $translatedDraft->content_id, (string) data_get($translatedDraft->meta, 'translation.translated_content_id'));
        $this->assertSame((string) $sourceDraft->id, (string) data_get($translatedDraft->brief->client_refs, 'translation.source_draft_id'));
        $this->assertSame(
            ['en', 'nl'],
            $sourceDraft->content->fresh()->normalizedLocalizationFamily()->pluck('language.value')->sort()->values()->all()
        );

        $contentSeo = Content::query()->findOrFail($translatedDraft->content_id)->seo()->first();

        $this->assertNotNull($contentSeo);
        $this->assertSame('hallo wereld', (string) $contentSeo->primary_keyword);
        $this->assertSame(['nederlandse vertaling'], (array) $contentSeo->secondary_keywords);
        $this->assertIsInt(data_get($translatedDraft->meta, 'human_content.locales.nl.after.human_content_score'));
        $this->assertSame('nl', data_get($translatedDraft->meta, 'translation.human_content.locale'));
    }

    public function test_create_translated_draft_persists_normalized_english_van_naar_title_and_slug(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::NL->value,
            'status' => 'ready',
            'title' => 'Van AI-chatbot naar AI-orchestratie',
            'content_html' => '<h1>Van AI-chatbot naar AI-orchestratie</h1><p>Nederlandse broncontent.</p>',
        ]);

        $translatedDraft = $this->service->createTranslatedDraft(
            $sourceDraft,
            SupportedLanguage::EN,
            [
                'title' => 'Van AI Chatbot to AI Orchestration: A Practical Decision Framework',
                'content_html' => '<h1>Van AI Chatbot to AI Orchestration</h1><p>English body text.</p>',
                'seo' => [
                    'seo_title' => 'Van AI Chatbot to AI Orchestration',
                    'seo_meta_description' => 'Van AI chatbot to orchestration without losing governance.',
                    'seo_h1' => 'Van AI Chatbot to AI Orchestration',
                    'slug' => 'van-ai-chatbot-to-ai-orchestration',
                    'suggested_primary_keyword' => 'AI orchestration',
                    'secondary_keywords' => ['AI chatbot'],
                ],
                'model_used' => 'gpt-4.1-mini',
            ],
            (string) $this->user->id
        );

        $translatedContent = $translatedDraft->content->fresh();

        $this->assertSame('From AI Chatbot to AI Orchestration: A Practical Decision Framework', (string) $translatedDraft->title);
        $this->assertSame('From AI Chatbot to AI Orchestration: A Practical Decision Framework', (string) $translatedContent->title);
        $this->assertSame('from-ai-chatbot-to-ai-orchestration-a-practical-decision-framework', (string) $translatedContent->publish_url_key);
        $this->assertSame('From AI Chatbot to AI Orchestration', (string) $translatedDraft->seo_title);
        $this->assertStringContainsString('<h1>From AI Chatbot to AI Orchestration</h1>', (string) $translatedDraft->content_html);
    }

    public function test_weak_translated_rhythm_can_be_humanized_in_target_locale(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<h1>Approval speed</h1><p>Original content with <a href="/en/blog/editorial-workflows">a link</a>.</p>',
        ]);

        $before = [
            'status' => 'fail',
            'passed' => false,
            'human_content_score' => 44,
            'editorial_quality_score' => 42,
            'originality_score' => 50,
            'uniqueness_score' => 62,
            'ai_fingerprint_score' => 68,
            'findings' => ['The translated rhythm is too uniform.'],
            'ai_fingerprint' => [
                'findings' => [['type' => 'uniform_paragraph_lengths']],
            ],
            'corpus_diversity' => ['score' => 100, 'findings' => []],
        ];
        $after = [
            'status' => 'pass',
            'passed' => true,
            'human_content_score' => 78,
            'editorial_quality_score' => 74,
            'originality_score' => 72,
            'uniqueness_score' => 80,
            'ai_fingerprint_score' => 24,
            'findings' => [],
            'ai_fingerprint' => ['findings' => []],
            'corpus_diversity' => ['score' => 100, 'findings' => []],
        ];

        $scorer = \Mockery::mock(HumanContentScoreService::class);
        $scorer->shouldReceive('scoreForDraft')->twice()->andReturn($before, $after);
        $this->app->instance(HumanContentScoreService::class, $scorer);

        $humanization = \Mockery::mock(HumanizationService::class);
        $humanization->shouldReceive('shouldHumanize')->once()->with($before)->andReturnTrue();
        $humanization->shouldReceive('humanize')->once()->andReturn([
            'version' => HumanizationService::VERSION,
            'status' => 'applied',
            'changed' => true,
            'improved_html' => '<h1>Waarom goedkeuring sneller kwaliteit bewaart</h1><p>Vertaald met natuurlijker ritme en <a href="/en/blog/editorial-workflows">een link</a>.</p>',
            'change_summary' => 'Improved translated rhythm.',
            'before_after_notes' => ['Varied sentence rhythm for Dutch.'],
            'preserved_validation' => ['passed' => true],
        ]);
        $this->app->instance(HumanizationService::class, $humanization);

        $translatedDraft = $this->app->make(TranslationService::class)->createTranslatedDraft(
            $sourceDraft,
            SupportedLanguage::NL,
            [
                'title' => 'Goedkeuringssnelheid',
                'content_html' => '<h1>Introduction</h1><p>Vertaald. Vertaald. Vertaald. <a href="/en/blog/editorial-workflows">een link</a>.</p>',
                'seo' => [
                    'seo_title' => 'Goedkeuringssnelheid',
                    'seo_meta_description' => 'Nederlandse meta beschrijving',
                    'seo_h1' => 'Goedkeuringssnelheid',
                    'slug' => 'goedkeuringssnelheid',
                    'suggested_primary_keyword' => 'goedkeuringssnelheid',
                    'secondary_keywords' => ['redactionele workflow'],
                ],
                'model_used' => 'gpt-4.1-mini',
            ],
            (string) $this->user->id
        );

        $this->assertStringContainsString('Waarom goedkeuring sneller kwaliteit bewaart', (string) $translatedDraft->content_html);
        $this->assertStringContainsString('href="/en/blog/editorial-workflows"', (string) $translatedDraft->content_html);
        $this->assertSame(44, data_get($translatedDraft->meta, 'human_content.locales.nl.before.human_content_score'));
        $this->assertSame(78, data_get($translatedDraft->meta, 'human_content.locales.nl.after.human_content_score'));
        $this->assertSame('applied', data_get($translatedDraft->meta, 'translation.human_content.humanization_status'));
    }

    public function test_create_translated_draft_refreshes_existing_target_locale_instead_of_creating_a_sibling(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<h1>Hello world</h1><p>Original content</p>',
        ]);

        $existingVariantDraft = $this->createUsableTranslatedDraft($sourceDraft, SupportedLanguage::NL);
        $existingVariantId = (string) $existingVariantDraft->content_id;

        $translatedDraft = $this->service->createTranslatedDraft(
            $sourceDraft,
            SupportedLanguage::NL,
            [
                'title' => 'Hallo wereld vernieuwd',
                'content_html' => '<h1>Hallo wereld vernieuwd</h1><p>Nieuwe inhoud.</p>',
                'seo' => [
                    'seo_title' => 'Hallo wereld vernieuwd',
                    'seo_meta_description' => 'Nieuwe Nederlandse meta beschrijving',
                    'seo_h1' => 'Hallo wereld vernieuwd',
                    'slug' => 'hallo-wereld-vernieuwd',
                    'suggested_primary_keyword' => 'hallo wereld vernieuwd',
                    'secondary_keywords' => ['vernieuwde vertaling'],
                ],
                'model_used' => 'gpt-4.1-mini',
            ],
            (string) $this->user->id
        );

        $this->assertSame($existingVariantId, (string) $translatedDraft->content_id);
        $this->assertCount(
            2,
            $sourceDraft->content->fresh()->normalizedLocalizationFamily()
        );
    }

    public function test_create_translated_draft_assigns_family_id_and_inherits_source_auto_publish_setting(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<h1>Hello world</h1><p>Original content</p>',
        ]);

        $sourceDraft->content->forceFill([
            'family_id' => (string) $sourceDraft->content_id,
            'auto_publish' => false,
            'sync_with_source' => true,
        ])->save();

        $translatedDraft = $this->service->createTranslatedDraft(
            $sourceDraft,
            SupportedLanguage::NL,
            [
                'title' => 'Hallo wereld',
                'content_html' => '<h1>Hallo wereld</h1><p>Vertaald</p>',
                'seo' => [
                    'seo_title' => 'Hallo wereld',
                    'seo_meta_description' => 'Nederlandse meta beschrijving',
                    'seo_h1' => 'Hallo wereld',
                    'slug' => 'hallo-wereld',
                    'suggested_primary_keyword' => 'hallo wereld',
                    'secondary_keywords' => ['nederlandse vertaling'],
                ],
                'model_used' => 'gpt-4.1-mini',
            ],
            (string) $this->user->id
        );

        $translatedContent = $translatedDraft->content->fresh();

        $this->assertSame((string) $sourceDraft->content_id, (string) $translatedContent->family_id);
        $this->assertFalse((bool) $translatedContent->auto_publish);
        $this->assertTrue((bool) $translatedContent->sync_with_source);
    }

    public function test_create_translated_draft_reuses_repairable_target_and_repairs_legacy_family_linkage(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<h1>Hello world</h1><p>Original content</p>',
        ]);

        $sourceDraft->content->forceFill([
            'external_key' => 'hello-world',
        ])->save();

        $repairableTargetId = (string) Str::uuid();
        $brokenFamilyRoot = Content::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Broken family root',
            'language' => SupportedLanguage::EN->value,
            'is_source_locale' => true,
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
            'publish_status' => 'draft',
            'external_key' => 'broken-family-root',
        ]);

        $payload = [
            'id' => $repairableTargetId,
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Legacy Dutch variant',
            'language' => SupportedLanguage::NL->value,
            'translation_source_content_id' => (string) $sourceDraft->content_id,
            'translation_source_locale' => SupportedLanguage::EN->value,
            'is_source_locale' => 0,
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
            'external_key' => 'hello-world-nl',
            'delivery_status' => 'pending',
            'publish_status' => 'draft',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ];

        if (Content::supportsFamilyId()) {
            $payload['family_id'] = (string) $brokenFamilyRoot->id;
        }

        DB::table('contents')->insert($payload);

        $translatedDraft = $this->service->createTranslatedDraft(
            $sourceDraft,
            SupportedLanguage::NL,
            [
                'title' => 'Hallo wereld',
                'content_html' => '<h1>Hallo wereld</h1><p>Vertaald</p>',
                'seo' => [
                    'seo_title' => 'Hallo wereld',
                    'seo_meta_description' => 'Nederlandse meta beschrijving',
                    'seo_h1' => 'Hallo wereld',
                    'slug' => 'hallo-wereld',
                    'suggested_primary_keyword' => 'hallo wereld',
                    'secondary_keywords' => ['nederlandse vertaling'],
                ],
                'model_used' => 'gpt-4.1-mini',
            ],
            (string) $this->user->id
        );

        $repairedContent = Content::query()->findOrFail($repairableTargetId);

        $this->assertSame($repairableTargetId, (string) $translatedDraft->content_id);
        $this->assertSame((string) $sourceDraft->content_id, (string) $repairedContent->translation_source_content_id);
        $this->assertSame('en', (string) $repairedContent->translation_source_locale);
        $this->assertSame('hello-world-nl', (string) $repairedContent->external_key);

        if (Content::supportsFamilyId()) {
            $this->assertSame((string) $sourceDraft->content_id, (string) $repairedContent->family_id);
        }
    }

    public function test_create_translated_draft_generates_fallback_external_key_when_proposed_key_is_taken(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<h1>Hello world</h1><p>Original content</p>',
        ]);

        $sourceDraft->content->forceFill([
            'external_key' => 'hello-world',
        ])->save();

        Content::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Existing unrelated Dutch content',
            'language' => SupportedLanguage::NL->value,
            'is_source_locale' => true,
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
            'publish_status' => 'draft',
            'external_key' => 'hello-world-nl',
        ]);

        $translatedDraft = $this->service->createTranslatedDraft(
            $sourceDraft,
            SupportedLanguage::NL,
            [
                'title' => 'Hallo wereld',
                'content_html' => '<h1>Hallo wereld</h1><p>Vertaald</p>',
                'seo' => [
                    'seo_title' => 'Hallo wereld',
                    'seo_meta_description' => 'Nederlandse meta beschrijving',
                    'seo_h1' => 'Hallo wereld',
                    'slug' => 'hallo-wereld',
                    'suggested_primary_keyword' => 'hallo wereld',
                    'secondary_keywords' => ['nederlandse vertaling'],
                ],
                'model_used' => 'gpt-4.1-mini',
            ],
            (string) $this->user->id
        );

        $this->assertSame('hello-world-nl-2', (string) $translatedDraft->content->external_key);
    }

    public function test_create_translated_draft_is_idempotent_for_repeated_source_and_target_locale_runs(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<h1>Hello world</h1><p>Original content</p>',
        ]);

        $firstTranslatedDraft = $this->service->createTranslatedDraft(
            $sourceDraft,
            SupportedLanguage::NL,
            [
                'title' => 'Hallo wereld',
                'content_html' => '<h1>Hallo wereld</h1><p>Eerste versie.</p>',
                'seo' => [
                    'seo_title' => 'Hallo wereld',
                    'seo_meta_description' => 'Eerste Nederlandse meta beschrijving',
                    'seo_h1' => 'Hallo wereld',
                    'slug' => 'hallo-wereld',
                    'suggested_primary_keyword' => 'hallo wereld',
                    'secondary_keywords' => ['eerste versie'],
                ],
                'model_used' => 'gpt-4.1-mini',
            ],
            (string) $this->user->id
        );

        $secondTranslatedDraft = $this->service->createTranslatedDraft(
            $sourceDraft,
            SupportedLanguage::NL,
            [
                'title' => 'Hallo wereld vernieuwd',
                'content_html' => '<h1>Hallo wereld vernieuwd</h1><p>Tweede versie.</p>',
                'seo' => [
                    'seo_title' => 'Hallo wereld vernieuwd',
                    'seo_meta_description' => 'Tweede Nederlandse meta beschrijving',
                    'seo_h1' => 'Hallo wereld vernieuwd',
                    'slug' => 'hallo-wereld-vernieuwd',
                    'suggested_primary_keyword' => 'hallo wereld vernieuwd',
                    'secondary_keywords' => ['tweede versie'],
                ],
                'model_used' => 'gpt-4.1-mini',
            ],
            (string) $this->user->id
        );

        $this->assertSame((string) $firstTranslatedDraft->content_id, (string) $secondTranslatedDraft->content_id);
        $this->assertSame(
            1,
            Content::query()
                ->where('client_site_id', $this->clientSite->id)
                ->where('language', SupportedLanguage::NL->value)
                ->where('translation_source_content_id', (string) $sourceDraft->content_id)
                ->count()
        );
    }

    public function test_refresh_translated_draft_preserves_manual_sync_override_on_existing_variant(): void
    {
        $sourceDraft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => SupportedLanguage::EN->value,
            'status' => 'ready',
            'content_html' => '<h1>Hello world</h1><p>Original content</p>',
        ]);

        $sourceDraft->content->forceFill([
            'family_id' => (string) $sourceDraft->content_id,
            'auto_publish' => true,
            'sync_with_source' => true,
        ])->save();

        $existingVariantDraft = $this->createUsableTranslatedDraft($sourceDraft, SupportedLanguage::NL);
        $existingVariant = $existingVariantDraft->content->fresh();
        $existingVariant->forceFill([
            'family_id' => (string) $sourceDraft->content_id,
            'sync_with_source' => false,
            'auto_publish' => false,
        ])->save();

        $translatedDraft = $this->service->refreshTranslatedDraft(
            $sourceDraft,
            $existingVariant->fresh(),
            SupportedLanguage::NL,
            [
                'title' => 'Hallo wereld vernieuwd',
                'content_html' => '<h1>Hallo wereld vernieuwd</h1><p>Nieuwe inhoud.</p>',
                'seo' => [
                    'seo_title' => 'Hallo wereld vernieuwd',
                    'seo_meta_description' => 'Nieuwe Nederlandse meta beschrijving',
                    'seo_h1' => 'Hallo wereld vernieuwd',
                    'slug' => 'hallo-wereld-vernieuwd',
                    'suggested_primary_keyword' => 'hallo wereld vernieuwd',
                    'secondary_keywords' => ['vernieuwde vertaling'],
                ],
                'model_used' => 'gpt-4.1-mini',
            ],
            (string) $this->user->id
        );

        $refreshedContent = $translatedDraft->content->fresh();

        $this->assertSame((string) $existingVariant->id, (string) $refreshedContent->id);
        $this->assertFalse((bool) $refreshedContent->sync_with_source);
        $this->assertFalse((bool) $refreshedContent->auto_publish);
        $this->assertSame((string) $sourceDraft->content_id, (string) $refreshedContent->family_id);
        $this->assertSame(
            1,
            Content::query()
                ->where('family_id', (string) $sourceDraft->content_id)
                ->where('language', SupportedLanguage::NL->value)
                ->count()
        );
    }

    private function createDraft(array $attributes): Draft
    {
        $contentLanguage = $attributes['content_language'] ?? $attributes['language'] ?? SupportedLanguage::EN->value;
        $briefLanguage = $attributes['brief_language'] ?? $attributes['language'] ?? SupportedLanguage::EN->value;

        unset($attributes['content_language'], $attributes['brief_language']);

        $content = Content::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Test Content ' . Str::random(6),
            'status' => 'draft',
            'language' => $contentLanguage,
            'is_source_locale' => true,
            'translation_source_locale' => $contentLanguage,
        ]);

        $brief = Brief::query()->create([
            'client_site_id' => $this->clientSite->id,
            'content_id' => $content->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Test Brief ' . Str::random(6),
            'language' => $briefLanguage,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
        ]);

        return Draft::query()->create(array_merge([
            'brief_id' => $brief->id,
            'content_id' => $content->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Test Draft ' . Str::random(6),
            'output_type' => 'kb_article',
        ], $attributes));
    }

    private function createUsableTranslatedDraft(Draft $sourceDraft, SupportedLanguage $targetLanguage): Draft
    {
        $translatedContent = Content::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Translated Content ' . Str::random(6),
            'status' => 'draft',
            'language' => $targetLanguage->value,
            'translation_source_content_id' => (string) $sourceDraft->content_id,
            'translation_source_locale' => $this->service->resolveSourceLanguage($sourceDraft)->value,
            'is_source_locale' => false,
            'publish_status' => 'draft',
        ]);

        $translatedBrief = Brief::query()->create([
            'client_site_id' => $this->clientSite->id,
            'content_id' => $translatedContent->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Translated Brief ' . Str::random(6),
            'language' => $targetLanguage->value,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
        ]);

        return Draft::query()->create([
            'brief_id' => $translatedBrief->id,
            'content_id' => $translatedContent->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Translated Draft ' . Str::random(6),
            'status' => 'ready',
            'language' => $targetLanguage->value,
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $sourceDraft->id,
            'translation_source_language' => $this->service->resolveSourceLanguage($sourceDraft)->value,
            'output_type' => 'kb_article',
            'content_html' => '<p>Translated body.</p>',
        ]);
    }
}
