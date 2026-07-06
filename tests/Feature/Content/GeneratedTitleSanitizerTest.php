<?php

use App\Enums\ContentAutomationTriggerType;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRunItem;
use App\Models\ContentChainSuggestion;
use App\Models\ContentSeriesGenerationRunArticle;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContentAutomation\ContentAutomationArticleService;
use App\Services\ContentAutomation\ContentAutomationOrchestrator;
use App\Services\ContentAutomation\ContentAutomationPlanner;
use App\Services\Content\TranslationDebugService;
use App\Services\Content\TranslationLockService;
use App\Services\HumanContent\HumanContentScoreService;
use App\Services\HumanContent\HumanizationService;
use App\Services\Llm\LlmManager;
use App\Services\Translation\SeoLocalizationService;
use App\Services\Translation\TranslationPromptBuilder;
use App\Services\Translation\TranslationService;
use App\Support\TitleSanitizer;
use App\Enums\SupportedLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists generated content titles within the database limit and keeps readable slugs', function () {
    [$workspace, $site] = makeGeneratedTitleContext();
    $longTitle = longGeneratedTitle();

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => $longTitle,
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'title' => $longTitle,
        'language' => 'en',
        'status' => 'draft',
        'source' => 'api',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'title' => $longTitle,
        'seo_title' => $longTitle,
        'seo_h1' => $longTitle,
        'status' => 'ready',
        'output_type' => 'kb_article',
        'content_html' => '<p>Generated content.</p>',
    ]);

    expect(mb_strlen((string) $content->fresh()->title))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and(mb_strlen((string) $brief->fresh()->title))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and(mb_strlen((string) $draft->fresh()->title))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and(Str::slug((string) $content->fresh()->title))->toContain('ai-cybersecurity');
});

it('automation runs succeed when the planner returns an oversized title', function () {
    [$workspace, $site, $user] = makeGeneratedTitleContext(withUser: true);

    $automation = ContentAutomation::query()->create([
        'organization_id' => (int) $workspace->organization_id,
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'name' => 'Long title automation',
        'is_active' => true,
        'is_paused' => false,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->subMinute(),
        'run_count' => 0,
        'chain_size' => 1,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Long title automation',
        'created_by' => (int) $user->id,
        'updated_by' => (int) $user->id,
        'settings' => [],
    ]);

    app(\App\Services\CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 25,
        type: \App\Services\CreditWalletService::TYPE_ADJUSTMENT,
        meta: ['source' => 'generated-title-sanitizer-test'],
    );

    $planner = Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'Long title chain',
        'chain_theme' => 'Long title',
        'source_locale' => 'en',
        'locales' => ['en'],
        'articles' => [
            ['sequence' => 1, 'title' => longGeneratedTitle(), 'target_locale' => 'en'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->once()->andReturnUsing(function (ContentAutomation $automation, $run, array $plan) {
        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $automation->workspace_id,
            'client_site_id' => (string) $automation->client_site_id,
            'title' => (string) $plan['title'],
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'automation',
            'automation_id' => (string) $automation->id,
            'automation_run_id' => (string) $run->id,
        ]);

        return [
            'content_id' => (string) $content->id,
            'draft_id' => (string) Str::uuid(),
            'published_content_ids' => [],
        ];
    });
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run($automation, ContentAutomationTriggerType::MANUAL, $user->id);
    $content = Content::query()->where('automation_run_id', (string) $run->id)->firstOrFail();
    $item = ContentAutomationRunItem::query()->where('automation_run_id', (string) $run->id)->firstOrFail();

    expect($run->status->value)->toBe('completed')
        ->and(mb_strlen((string) $content->title))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and(mb_strlen((string) $item->title))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH);
});

it('chained and series generated title tracking records are sanitized before save', function () {
    [$workspace, $site] = makeGeneratedTitleContext();
    $longTitle = longGeneratedTitle();

    $source = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Source content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
    ]);

    $suggestion = ContentChainSuggestion::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'source_content_id' => (string) $source->id,
        'fingerprint' => 'long-title-' . Str::lower(Str::random(8)),
        'suggestion_kind' => ContentChainSuggestion::KIND_GROWTH,
        'suggestion_type' => 'growth',
        'title' => $longTitle,
        'status' => ContentChainSuggestion::STATUS_SUGGESTED,
        'score' => 70,
        'meta' => ['target_keyword' => 'AI cybersecurity'],
    ]);

    $runArticle = ContentSeriesGenerationRunArticle::query()->make([
        'title' => $longTitle,
    ]);

    expect(mb_strlen((string) $suggestion->title))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and(mb_strlen((string) $runArticle->title))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH);
});

it('translated content generation persists a safe localized title', function () {
    [$workspace, $site] = makeGeneratedTitleContext();
    $sourceContent = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Nederlandse bron',
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => null,
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
    ]);
    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $sourceContent->id,
        'title' => 'Nederlandse brief',
        'language' => SupportedLanguage::NL->value,
        'status' => 'done',
        'source' => 'api',
        'output_type' => 'kb_article',
    ]);
    $sourceDraft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $sourceContent->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready',
        'title' => 'Nederlandse draft',
        'output_type' => 'kb_article',
        'language' => SupportedLanguage::NL->value,
        'content_html' => '<p>Broncontent.</p>',
    ]);

    $seo = Mockery::mock(SeoLocalizationService::class);
    $seo->shouldReceive('buildLocalizedSeoMetadata')->once()->andReturnUsing(function (Draft $draft, string $title): array {
        return [
            'slug' => Str::slug($title),
            'seo_title' => $title,
            'seo_meta_description' => 'English meta description',
            'seo_h1' => $title,
            'seo_canonical' => null,
            'seo_og_title' => $title,
            'seo_og_description' => null,
            'seo_og_image' => null,
            'seo_twitter_title' => $title,
            'seo_twitter_description' => null,
            'primary_keyword' => 'AI cybersecurity',
            'secondary_keywords' => [],
            'needs_review' => false,
        ];
    });

    $service = new TranslationService(
        Mockery::mock(LlmManager::class),
        Mockery::mock(TranslationPromptBuilder::class),
        $seo,
        app(TranslationLockService::class),
        app(TranslationDebugService::class),
        app(HumanContentScoreService::class),
        app(HumanizationService::class),
    );

    $translatedDraft = $service->createTranslatedDraft($sourceDraft, SupportedLanguage::EN, [
        'title' => longGeneratedTitle(),
        'content_html' => '<p>English translation.</p>',
        'seo' => [],
        'model_used' => 'test-model',
    ]);

    expect(mb_strlen((string) $translatedDraft->title))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and(mb_strlen((string) $translatedDraft->content->title))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and((string) data_get($translatedDraft->meta, 'translation.original_title'))->toContain('AI cybersecurity');
});

function makeGeneratedTitleContext(bool $withUser = false): array
{
    $organization = Organization::query()->create([
        'name' => 'Generated Title Org',
        'slug' => 'generated-title-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Generated Title Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Generated Title Site',
        'site_url' => 'https://generated-title.example.com',
        'base_url' => 'https://generated-title.example.com',
        'allowed_domains' => ['generated-title.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    if (! $withUser) {
        return [$workspace, $site];
    }

    $user = User::query()->create([
        'name' => 'Generated Title User',
        'email' => 'generated-title-' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return [$workspace, $site, $user];
}

function longGeneratedTitle(): string
{
    return implode(' ', array_fill(0, 32, 'AI cybersecurity as an architectural layer for generated content systems'));
}
