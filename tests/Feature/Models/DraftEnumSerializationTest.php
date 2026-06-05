<?php

use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('Draft Enum Serialization', function () {
    it('can serialize draft with language enum to array', function () {
        $draft = new Draft();
        $draft->language = 'en';
        $draft->draft_type = 'original';

        $array = $draft->toArray();

        expect($array['language'])->toBe('en');
        expect($array['draft_type'])->toBe('original');
    });

    it('can serialize draft with language enum to json', function () {
        $draft = new Draft();
        $draft->language = 'en';
        $draft->draft_type = 'translation';

        $json = json_encode($draft->toArray());
        $decoded = json_decode($json, true);

        expect($decoded['language'])->toBe('en');
        expect($decoded['draft_type'])->toBe('translation');
    });

    it('correctly casts language attribute to enum', function () {
        $draft = new Draft();
        $draft->language = 'nl';

        expect($draft->language)->toBeInstanceOf(SupportedLanguage::class);
        expect($draft->language)->toBe(SupportedLanguage::NL);
        expect($draft->language->value)->toBe('nl');
    });

    it('correctly casts draft_type attribute to enum', function () {
        $draft = new Draft();
        $draft->draft_type = 'hybrid';

        expect($draft->draft_type)->toBeInstanceOf(DraftType::class);
        expect($draft->draft_type)->toBe(DraftType::HYBRID);
        expect($draft->draft_type->value)->toBe('hybrid');
    });

    it('can save and retrieve draft with enum attributes', function () {
        [$workspace, $site, $brief, $content] = createDraftEnumTestContext();

        $draft = Draft::create([
            'id' => (string) Str::uuid(),
            'brief_id' => $brief->id,
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'title' => 'Test Draft',
            'language' => 'de',
            'draft_type' => 'original',
            'status' => 'generated',
            'output_type' => 'article',
            'content_html' => '<p>Test</p>',
        ]);

        $retrieved = Draft::find($draft->id);

        expect($retrieved->language)->toBeInstanceOf(SupportedLanguage::class);
        expect($retrieved->language)->toBe(SupportedLanguage::DE);
        expect($retrieved->draft_type)->toBeInstanceOf(DraftType::class);
        expect($retrieved->draft_type)->toBe(DraftType::ORIGINAL);

        // Verify toArray still works after retrieval
        $array = $retrieved->toArray();
        expect($array['language'])->toBe('de');
        expect($array['draft_type'])->toBe('original');
    });

    it('serializes draft with translations correctly', function () {
        [$workspace, $site, $brief, $content] = createDraftEnumTestContext();

        $sourceDraft = Draft::create([
            'id' => (string) Str::uuid(),
            'brief_id' => $brief->id,
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'title' => 'Source Draft',
            'language' => 'en',
            'draft_type' => 'original',
            'status' => 'generated',
            'output_type' => 'article',
            'content_html' => '<p>English</p>',
        ]);

        $translatedContent = Content::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'NL Content',
            'primary_keyword' => 'test',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'system',
            'language' => 'nl',
        ]);

        $translatedDraft = Draft::create([
            'id' => (string) Str::uuid(),
            'brief_id' => $brief->id,
            'content_id' => $translatedContent->id,
            'client_site_id' => $site->id,
            'source_draft_id' => $sourceDraft->id,
            'title' => 'Translated Draft',
            'language' => 'nl',
            'draft_type' => 'translation',
            'translation_source_language' => 'en',
            'status' => 'generated',
            'output_type' => 'article',
            'content_html' => '<p>Nederlands</p>',
        ]);

        // Load with translations
        $sourceDraft->load('translations');

        // Access the translation language (this was causing the original issue)
        $translations = $sourceDraft->translations->sortBy(
            fn (Draft $t): string => $t->language->value
        );

        expect($translations)->toHaveCount(1);
        expect($translations->first()->language->value)->toBe('nl');

        // Serialize to array
        $array = $sourceDraft->toArray();
        expect($array['language'])->toBe('en');
    });
});

/**
 * Create test context for draft enum tests.
 *
 * @return array{Workspace, ClientSite, Brief, Content}
 */
function createDraftEnumTestContext(): array
{
    $organization = Organization::create([
        'name' => 'Enum Test Org',
        'slug' => 'enum-test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Enum Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Enum Test Site',
        'site_url' => 'https://enum-test.example.com',
        'base_url' => 'https://enum-test.example.com',
        'allowed_domains' => ['enum-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Test Content',
        'primary_keyword' => 'test',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'system',
        'language' => 'en',
    ]);

    $brief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Test Brief',
        'language' => 'en',
        'output_type' => 'article',
    ]);

    return [$workspace, $site, $brief, $content];
}
