<?php

use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Content\ContentLifecycleService;
use App\Services\Content\ContentLocalizationService;
use App\Services\Content\LocaleMismatchService;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use App\Support\LanguageDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeLocaleMismatchTestContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Locale Mismatch Test Org',
        'slug' => 'locale-mismatch-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Locale Test BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Locale Mismatch Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'nl',
        'enabled_content_languages' => ['nl', 'en'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Locale Test Site',
        'site_url' => 'https://locale-test.example.com',
        'allowed_domains' => ['locale-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'locale-mismatch-test-plan'],
        [
            'name' => 'Locale Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::query()->create([
        'name' => 'Locale Test User',
        'email' => 'locale-test-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return [$workspace, $site, $user];
}

function makeContentWithBody(Workspace $workspace, ClientSite $site, array $contentData, string $body): Content
{
    $content = Content::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'primary_keyword' => 'test keyword',
        'status' => 'active',
    ], $contentData));

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => $body,
        'source' => 'pl',
    ]);

    $content->current_version_id = $version->id;
    $content->save();
    $content->load('currentVersion');

    return $content;
}

describe('LocaleMismatchService', function () {
    beforeEach(function () {
        $this->detector = new LanguageDetector();
        $this->localizationService = app(ContentLocalizationService::class);
        $this->service = new LocaleMismatchService($this->detector, $this->localizationService);
    });

    describe('analyze', function () {
        it('detects mismatch when Dutch content is marked as English', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $content = makeContentWithBody($workspace, $site, [
                'title' => 'Dit is een Nederlandse titel voor de test',
                'language' => SupportedLanguage::EN,
                'is_source_locale' => true,
            ], '<p>Dit is een uitgebreide Nederlandse tekst met veel woorden. We hebben deze tekst nodig om te testen of de detectie correct werkt. Het systeem moet de taal goed herkennen en de juiste suggestie geven.</p>');

            $result = $this->service->analyze($content);

            expect($result['has_mismatch'])->toBeTrue();
            expect($result['declared_locale'])->toBe('en');
            expect($result['detected_locale'])->toBe('nl');
            expect($result['confidence'])->toBeGreaterThan(0.6);
        });

        it('returns no mismatch when locale matches content', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $content = makeContentWithBody($workspace, $site, [
                'title' => 'Dit is een Nederlandse titel voor de test',
                'language' => SupportedLanguage::NL,
                'is_source_locale' => true,
            ], '<p>Dit is een uitgebreide Nederlandse tekst met veel woorden. We hebben deze tekst nodig om te testen of de detectie correct werkt.</p>');

            $result = $this->service->analyze($content);

            expect($result['has_mismatch'])->toBeFalse();
        });

        it('returns insufficient content for short text', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $content = makeContentWithBody($workspace, $site, [
                'title' => 'Short',
                'language' => SupportedLanguage::EN,
            ], '<p>Short</p>');

            $result = $this->service->analyze($content);

            expect($result['has_mismatch'])->toBeFalse();
            expect($result['confidence'])->toBe(0.0);
            expect(data_get($result, 'analysis.reason'))->toBe('insufficient_content');
        });
    });

    describe('fixLocale', function () {
        it('changes locale to detected language', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $content = makeContentWithBody($workspace, $site, [
                'title' => 'Test Content',
                'language' => SupportedLanguage::EN,
                'is_source_locale' => true,
            ], '<p>Test body content for fixing locale.</p>');

            $result = $this->service->fixLocale($content, SupportedLanguage::NL);

            expect($result['success'])->toBeTrue();
            expect($result['old_locale'])->toBe('en');
            expect($result['new_locale'])->toBe('nl');

            $content->refresh();
            expect($content->language)->toBe(SupportedLanguage::NL);
        });

        it('returns success without changes when locale already correct', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $content = makeContentWithBody($workspace, $site, [
                'title' => 'Test Content',
                'language' => SupportedLanguage::NL,
            ], '<p>Test body.</p>');

            $result = $this->service->fixLocale($content, SupportedLanguage::NL);

            expect($result['success'])->toBeTrue();
            expect($result['message'])->toBe('Locale already correct');
        });

        it('prevents fixing to duplicate locale in family', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $source = makeContentWithBody($workspace, $site, [
                'title' => 'Source Content',
                'language' => SupportedLanguage::EN,
                'is_source_locale' => true,
            ], '<p>Source body.</p>');

            $source->family_id = $source->id;
            $source->save();

            $variant = makeContentWithBody($workspace, $site, [
                'title' => 'Variant Content',
                'language' => SupportedLanguage::NL,
                'family_id' => $source->id,
                'translation_source_content_id' => $source->id,
                'is_source_locale' => false,
            ], '<p>Variant body.</p>');

            // Try to fix source to NL, which would conflict with variant
            $result = $this->service->fixLocale($source, SupportedLanguage::NL);

            expect($result['success'])->toBeFalse();
            expect($result['message'])->toContain('duplicate');
        });
    });

    describe('fix locale command', function () {
        it('translates English text into the declared Dutch locale in place and updates the slug', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $englishBody = '<p>This article explains how AI search visibility works for marketing teams. It covers practical questions, strategic implications, common mistakes, and a repeatable framework for improving content visibility in generated answers.</p><p>The goal is to help teams decide what to fix before investing in SEO fundamentals.</p>';
            $translatedBody = '<p>Dit artikel legt uit hoe AI-zoekzichtbaarheid werkt voor marketingteams. Het behandelt praktische vragen, strategische gevolgen, veelgemaakte fouten en een herhaalbaar raamwerk om content beter zichtbaar te maken in gegenereerde antwoorden.</p><p>Het doel is teams te helpen bepalen wat ze moeten verbeteren voordat ze investeren in SEO-fundamenten.</p>';

            $content = makeContentWithBody($workspace, $site, [
                'title' => 'A practical framework for AI content visibility',
                'language' => SupportedLanguage::NL,
                'is_source_locale' => true,
                'type' => 'article',
                'status' => 'published',
                'publish_status' => 'published',
                'publish_url_key' => 'a-practical-framework-for-ai-content-visibility',
                'canonical_url_key' => 'a-practical-framework-for-ai-content-visibility',
                'seo_title' => 'A practical framework for AI content visibility',
                'seo_meta_description' => 'A practical framework for improving AI content visibility before investing in SEO fundamentals.',
            ], $englishBody);
            $content->forceFill([
                'family_id' => (string) $content->id,
            ])->saveQuietly();

            $brief = Brief::query()->create([
                'id' => (string) Str::uuid(),
                'client_site_id' => (string) $site->id,
                'created_by_user_id' => (int) $user->id,
                'content_id' => (string) $content->id,
                'status' => 'done',
                'source' => 'test',
                'progress' => 1,
                'title' => $content->title,
                'language' => SupportedLanguage::NL->value,
                'content_type' => 'blog',
                'output_type' => 'kb_article',
                'primary_keyword' => 'AI content visibility',
            ]);

            $draft = Draft::query()->create([
                'id' => (string) Str::uuid(),
                'brief_id' => (string) $brief->id,
                'content_id' => (string) $content->id,
                'client_site_id' => (string) $site->id,
                'status' => 'ready',
                'title' => $content->title,
                'language' => SupportedLanguage::NL->value,
                'draft_type' => 'original',
                'output_type' => 'kb_article',
                'content_html' => $englishBody,
                'seo_title' => $content->seo_title,
                'seo_meta_description' => $content->seo_meta_description,
                'meta' => ['language' => SupportedLanguage::NL->value],
            ]);

            $translation = [
                'title' => 'Praktisch raamwerk voor AI-contentzichtbaarheid',
                'content_html' => $translatedBody,
                'seo' => [
                    'seo_title' => 'Praktisch raamwerk voor AI-contentzichtbaarheid',
                    'seo_meta_description' => 'Een praktisch raamwerk om AI-contentzichtbaarheid te verbeteren voordat je investeert in SEO-fundamenten.',
                    'seo_h1' => 'Praktisch raamwerk voor AI-contentzichtbaarheid',
                    'seo_og_title' => 'Praktisch raamwerk voor AI-contentzichtbaarheid',
                    'seo_og_description' => 'Verbeter AI-contentzichtbaarheid met praktische keuzes voor SEO-fundamenten.',
                    'slug' => 'praktisch-raamwerk-voor-ai-contentzichtbaarheid',
                    'suggested_primary_keyword' => 'AI-contentzichtbaarheid',
                    'secondary_keywords' => ['AI-zoekoptimalisatie', 'SEO-fundamenten'],
                ],
                'translation_notes' => '',
            ];

            $llm = \Mockery::mock(LlmManager::class);
            $llm->shouldReceive('generateJson')
                ->once()
                ->withArgs(function (LlmRequest $request, array $schema) use ($content): bool {
                    $prompt = collect($request->messages)
                        ->map(fn ($message) => $message->content)
                        ->implode("\n");

                    return $request->metadata['feature'] === 'content_declared_locale_translation_repair'
                        && $request->metadata['content_id'] === (string) $content->id
                        && $request->metadata['source_language'] === 'en'
                        && $request->metadata['target_language'] === 'nl'
                        && str_contains($prompt, 'Translate the following content from English to Dutch.')
                        && str_contains($prompt, 'Suggest a localized slug')
                        && data_get($schema, 'schema.properties.seo.properties.slug.type') === 'string';
                })
                ->andReturn(new LlmResponse(
                    text: json_encode($translation),
                    json: $translation,
                    usage: new LlmUsage(120, 180, 300),
                    modelUsed: 'gpt-4.1-mini',
                    providerName: 'openai',
                    requestId: 'repair-request-1',
                ));
            $this->app->instance(LlmManager::class, $llm);

            $this->artisan('content:fix-locale-mismatches', [
                '--auto-fix' => true,
                '--translate-to-declared' => true,
                '--declared-locale' => 'nl',
                '--limit' => 10,
                '--min-confidence' => 0.6,
            ])->assertExitCode(0);

            $fresh = $content->fresh(['currentVersion', 'brief', 'drafts', 'seo']);
            $freshDraft = $draft->fresh();
            $freshBrief = $brief->fresh();

            expect($fresh->localeCode())->toBe('nl')
                ->and((bool) $fresh->is_source_locale)->toBeTrue()
                ->and((string) $fresh->family_id)->toBe((string) $content->id)
                ->and($fresh->status)->toBe('published')
                ->and($fresh->publish_status)->toBe('published')
                ->and($fresh->title)->toBe('Praktisch raamwerk voor AI-contentzichtbaarheid')
                ->and($fresh->publish_url_key)->toBe('praktisch-raamwerk-voor-ai-contentzichtbaarheid')
                ->and($fresh->currentVersion->body)->toContain('Dit artikel legt uit')
                ->and((string) data_get($fresh->locale_repair_meta, 'last_translate_to_declared_locale.source_locale'))->toBe('en')
                ->and((string) data_get($fresh->locale_repair_meta, 'last_translate_to_declared_locale.target_locale'))->toBe('nl')
                ->and((string) data_get($fresh->locale_repair_meta, 'last_translate_to_declared_locale.request_id'))->toBe('repair-request-1')
                ->and($freshDraft?->title)->toBe('Praktisch raamwerk voor AI-contentzichtbaarheid')
                ->and((string) $freshDraft?->getRawOriginal('language'))->toBe('nl')
                ->and($freshDraft?->content_html)->toContain('Dit artikel legt uit')
                ->and((string) data_get($freshDraft?->meta, 'locale_repair.repair_type'))->toBe('translate_to_declared_locale')
                ->and($freshBrief?->title)->toBe('Praktisch raamwerk voor AI-contentzichtbaarheid')
                ->and((string) $freshBrief?->language)->toBe('nl')
                ->and($fresh->seo?->meta_title)->toBe('Praktisch raamwerk voor AI-contentzichtbaarheid');
        });

        it('auto-fixes inverted source and translation locale rows by swapping them', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $englishBody = '<p>This article explains how AI search visibility works for marketing teams. It covers practical questions, strategic implications, common mistakes, and a repeatable framework for improving content visibility in generated answers.</p>';
            $dutchBody = '<p>Dit artikel legt uit hoe AI vindbaarheid werkt voor marketingteams. Het behandelt praktische vragen, strategische keuzes, veelgemaakte fouten en een herhaalbaar raamwerk om content beter zichtbaar te maken in gegenereerde antwoorden.</p>';

            $source = makeContentWithBody($workspace, $site, [
                'title' => 'Practical framework for AI content visibility',
                'language' => SupportedLanguage::NL,
                'is_source_locale' => true,
                'type' => 'article',
                'status' => 'published',
            ], $englishBody);
            $source->forceFill([
                'family_id' => (string) $source->id,
            ])->saveQuietly();

            $variant = makeContentWithBody($workspace, $site, [
                'title' => 'Praktisch raamwerk voor AI vindbaarheid',
                'language' => SupportedLanguage::EN,
                'family_id' => (string) $source->id,
                'translation_source_content_id' => (string) $source->id,
                'translation_source_version_id' => (string) $source->current_version_id,
                'translation_source_locale' => SupportedLanguage::NL->value,
                'is_source_locale' => false,
                'type' => 'article',
                'status' => 'published',
            ], $dutchBody);

            $sourceBrief = Brief::query()->create([
                'id' => (string) Str::uuid(),
                'client_site_id' => (string) $site->id,
                'created_by_user_id' => (int) $user->id,
                'content_id' => (string) $source->id,
                'status' => 'done',
                'source' => 'test',
                'progress' => 1,
                'title' => $source->title,
                'language' => SupportedLanguage::NL->value,
                'content_type' => 'blog',
                'output_type' => 'kb_article',
            ]);
            $variantBrief = Brief::query()->create([
                'id' => (string) Str::uuid(),
                'client_site_id' => (string) $site->id,
                'created_by_user_id' => (int) $user->id,
                'content_id' => (string) $variant->id,
                'status' => 'done',
                'source' => 'test',
                'progress' => 1,
                'title' => $variant->title,
                'language' => SupportedLanguage::EN->value,
                'content_type' => 'blog',
                'output_type' => 'kb_article',
            ]);

            $sourceDraft = Draft::query()->create([
                'id' => (string) Str::uuid(),
                'brief_id' => (string) $sourceBrief->id,
                'content_id' => (string) $source->id,
                'client_site_id' => (string) $site->id,
                'status' => 'ready',
                'title' => $source->title,
                'language' => SupportedLanguage::NL->value,
                'draft_type' => 'original',
                'output_type' => 'kb_article',
                'content_html' => $englishBody,
                'meta' => ['language' => SupportedLanguage::NL->value],
            ]);
            $variantDraft = Draft::query()->create([
                'id' => (string) Str::uuid(),
                'brief_id' => (string) $variantBrief->id,
                'content_id' => (string) $variant->id,
                'client_site_id' => (string) $site->id,
                'status' => 'ready',
                'title' => $variant->title,
                'language' => SupportedLanguage::EN->value,
                'draft_type' => 'translation',
                'output_type' => 'kb_article',
                'content_html' => $dutchBody,
                'meta' => ['language' => SupportedLanguage::EN->value],
            ]);

            $analysis = app(LocaleMismatchService::class)->analyze($source->fresh(['currentVersion']));
            expect($analysis['fix_action'])->toBe('swap_inverted_locales')
                ->and($analysis['can_swap_locales'])->toBeTrue();

            $this->artisan('content:fix-locale-mismatches', [
                '--auto-fix' => true,
                '--limit' => 10,
                '--min-confidence' => 0.6,
            ])->assertExitCode(0);

            $source->refresh();
            $variant->refresh();

            expect($source->localeCode())->toBe('en')
                ->and((bool) $source->is_source_locale)->toBeFalse()
                ->and((string) $source->translation_source_content_id)->toBe((string) $variant->id)
                ->and((string) $source->family_id)->toBe((string) $variant->id)
                ->and($variant->localeCode())->toBe('nl')
                ->and((bool) $variant->is_source_locale)->toBeTrue()
                ->and($variant->translation_source_content_id)->toBeNull()
                ->and((string) $variant->family_id)->toBe((string) $variant->id)
                ->and((string) $sourceBrief->fresh()->language)->toBe('en')
                ->and((string) $variantBrief->fresh()->language)->toBe('nl')
                ->and((string) $sourceDraft->fresh()->getRawOriginal('language'))->toBe('en')
                ->and((string) $variantDraft->fresh()->getRawOriginal('language'))->toBe('nl')
                ->and((string) data_get($sourceDraft->fresh()->meta, 'language'))->toBe('en')
                ->and((string) data_get($variantDraft->fresh()->meta, 'language'))->toBe('nl');
        });
    });

    describe('generation flow', function () {
        it('auto-corrects english source locale to dutch when persisting a dutch draft revision', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $content = Content::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => (string) $workspace->id,
                'client_site_id' => (string) $site->id,
                'title' => 'Nederlandse bron',
                'language' => SupportedLanguage::EN,
                'is_source_locale' => true,
                'status' => 'draft',
                'type' => 'article',
                'source' => 'manual',
                'external_key' => (string) Str::uuid(),
                'publish_status' => 'draft',
            ]);

            $brief = \App\Models\Brief::query()->create([
                'id' => (string) Str::uuid(),
                'client_site_id' => (string) $site->id,
                'created_by_user_id' => (int) $user->id,
                'content_id' => (string) $content->id,
                'status' => 'done',
                'source' => 'client_ui',
                'progress' => 1,
                'title' => 'Nederlandse brief',
                'language' => SupportedLanguage::EN->value,
                'content_type' => 'blog',
                'output_type' => 'kb_article',
            ]);

            $draft = \App\Models\Draft::query()->create([
                'id' => (string) Str::uuid(),
                'brief_id' => (string) $brief->id,
                'content_id' => (string) $content->id,
                'client_site_id' => (string) $site->id,
                'status' => 'ready',
                'title' => 'Nederlandse draft',
                'language' => SupportedLanguage::EN->value,
                'draft_type' => 'original',
                'output_type' => 'kb_article',
                'content_html' => '<p>Dit is een uitgebreide Nederlandse tekst met genoeg woorden om de automatische locale-correctie tijdens generatie te activeren voor de broninhoud.</p>',
                'meta' => [
                    'language' => SupportedLanguage::EN->value,
                ],
            ]);

            app(ContentLifecycleService::class)->ensureRevisionFromDraft($draft, (int) $user->id);

            expect($content->fresh()->localeCode())->toBe('nl')
                ->and((string) $brief->fresh()->language)->toBe('nl')
                ->and($draft->fresh()->language)->toBe(SupportedLanguage::NL)
                ->and((string) data_get($draft->fresh()->meta, 'language'))->toBe('nl');
        });
    });

    describe('validateSourceForTranslation', function () {
        it('returns valid for correct source content', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $content = makeContentWithBody($workspace, $site, [
                'title' => 'This is an English title for testing',
                'language' => SupportedLanguage::EN,
                'is_source_locale' => true,
            ], '<p>This is an extensive English text with many words. We need this text to test if the detection works correctly in all cases and validates properly.</p>');

            $result = $this->service->validateSourceForTranslation($content);

            expect($result['valid'])->toBeTrue();
            expect($result['issues'])->toBeEmpty();
        });

        it('returns issues for locale mismatch', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $content = makeContentWithBody($workspace, $site, [
                'title' => 'Dit is een Nederlandse titel voor de test',
                'language' => SupportedLanguage::EN,
                'is_source_locale' => true,
            ], '<p>Dit is een uitgebreide Nederlandse tekst met veel woorden. We hebben deze tekst nodig om te testen of de detectie correct werkt.</p>');

            $result = $this->service->validateSourceForTranslation($content);

            expect($result['valid'])->toBeFalse();
            expect($result['issues'])->not->toBeEmpty();
            expect($result['suggested_fix'])->toBe('fix_locale_to_nl');
        });
    });

    describe('enforceSingleSourcePerFamily', function () {
        it('keeps oldest content as source', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $source1 = makeContentWithBody($workspace, $site, [
                'title' => 'First Source',
                'language' => SupportedLanguage::EN,
                'is_source_locale' => true,
                'created_at' => now()->subDays(2),
            ], '<p>First source body.</p>');

            $source1->family_id = $source1->id;
            $source1->save();

            $source2 = makeContentWithBody($workspace, $site, [
                'title' => 'Second Source',
                'language' => SupportedLanguage::NL,
                'created_at' => now()->subDay(),
            ], '<p>Second source body.</p>');

            // Explicitly set family_id and is_source_locale after creation to bypass observer logic
            $source2->family_id = $source1->id;
            $source2->is_source_locale = true;
            $source2->saveQuietly();

            $result = $this->service->enforceSingleSourcePerFamily($source1->id);

            expect($result['fixed'])->toBe(1);

            $source1->refresh();
            $source2->refresh();

            expect($source1->is_source_locale)->toBeTrue();
            expect($source2->is_source_locale)->toBeFalse();
            expect($source2->translation_source_content_id)->toBe($source1->id);
        });

        it('does nothing when family has single source', function () {
            [$workspace, $site, $user] = makeLocaleMismatchTestContext();

            $source = makeContentWithBody($workspace, $site, [
                'title' => 'Source Content',
                'language' => SupportedLanguage::EN,
                'is_source_locale' => true,
            ], '<p>Source body.</p>');

            $source->family_id = $source->id;
            $source->save();

            $variant = makeContentWithBody($workspace, $site, [
                'title' => 'Variant Content',
                'language' => SupportedLanguage::NL,
                'family_id' => $source->id,
                'is_source_locale' => false,
                'translation_source_content_id' => $source->id,
            ], '<p>Variant body.</p>');

            $result = $this->service->enforceSingleSourcePerFamily($source->id);

            expect($result['fixed'])->toBe(0);
        });
    });
});
