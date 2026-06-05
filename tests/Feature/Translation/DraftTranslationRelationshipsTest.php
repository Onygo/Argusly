<?php

namespace Tests\Feature\Translation;

use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DraftTranslationRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;
    protected Workspace $workspace;
    protected ClientSite $clientSite;
    protected Content $content;
    protected Brief $brief;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->content = Content::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Test Content',
            'status' => 'draft',
        ]);

        $this->brief = Brief::query()->create([
            'client_site_id' => $this->clientSite->id,
            'content_id' => $this->content->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Test Brief',
            'language' => 'en',
            'content_type' => 'blog',
            'output_type' => 'kb_article',
        ]);
    }

    private function createDraft(array $attributes = []): Draft
    {
        return Draft::query()->create(array_merge([
            'brief_id' => $this->brief->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Test Draft ' . Str::random(6),
            'status' => 'ready',
            'output_type' => 'kb_article',
        ], $attributes));
    }

    public function test_draft_has_language_attribute(): void
    {
        $draft = $this->createDraft([
            'language' => SupportedLanguage::EN->value,
        ]);

        $this->assertSame(SupportedLanguage::EN, $draft->language);
    }

    public function test_draft_has_draft_type_attribute(): void
    {
        $draft = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
        ]);

        $this->assertSame(DraftType::ORIGINAL, $draft->draft_type);
    }

    public function test_original_draft_can_have_translations(): void
    {
        $originalDraft = $this->createDraft([
            'language' => SupportedLanguage::EN->value,
            'draft_type' => DraftType::ORIGINAL->value,
        ]);

        $nlTranslation = $this->createDraft([
            'language' => SupportedLanguage::NL->value,
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $originalDraft->id,
        ]);

        $deTranslation = $this->createDraft([
            'language' => SupportedLanguage::DE->value,
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $originalDraft->id,
        ]);

        $translations = $originalDraft->translations;

        $this->assertCount(2, $translations);
        $this->assertTrue($translations->contains('id', $nlTranslation->id));
        $this->assertTrue($translations->contains('id', $deTranslation->id));
    }

    public function test_translation_draft_has_source_draft(): void
    {
        $originalDraft = $this->createDraft([
            'language' => SupportedLanguage::EN->value,
            'draft_type' => DraftType::ORIGINAL->value,
        ]);

        $translation = $this->createDraft([
            'language' => SupportedLanguage::NL->value,
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $originalDraft->id,
        ]);

        $this->assertNotNull($translation->sourceDraft);
        $this->assertSame($originalDraft->id, $translation->sourceDraft->id);
    }

    public function test_is_translation_returns_correctly(): void
    {
        $original = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
        ]);

        $translation = $this->createDraft([
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $original->id,
        ]);

        $this->assertFalse($original->isTranslation());
        $this->assertTrue($translation->isTranslation());
    }

    public function test_is_original_returns_correctly(): void
    {
        $original = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
        ]);

        $translation = $this->createDraft([
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $original->id,
        ]);

        $this->assertTrue($original->isOriginal());
        $this->assertFalse($translation->isOriginal());
    }

    public function test_can_be_translated_returns_correctly(): void
    {
        $original = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
        ]);

        $hybrid = $this->createDraft([
            'draft_type' => DraftType::HYBRID->value,
        ]);

        $translation = $this->createDraft([
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $original->id,
        ]);

        $this->assertTrue($original->canBeTranslated());
        $this->assertTrue($hybrid->canBeTranslated());
        $this->assertFalse($translation->canBeTranslated());
    }

    public function test_get_original_source_draft_returns_self_for_original(): void
    {
        $original = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
        ]);

        $this->assertSame($original->id, $original->getOriginalSourceDraft()->id);
    }

    public function test_get_original_source_draft_returns_source_for_translation(): void
    {
        $original = $this->createDraft([
            'draft_type' => DraftType::ORIGINAL->value,
        ]);

        $translation = $this->createDraft([
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $original->id,
        ]);

        $this->assertSame($original->id, $translation->getOriginalSourceDraft()->id);
    }

    public function test_has_translation_for_language_returns_correctly(): void
    {
        $original = $this->createDraft([
            'language' => SupportedLanguage::EN->value,
            'draft_type' => DraftType::ORIGINAL->value,
        ]);

        $this->createDraft([
            'language' => SupportedLanguage::NL->value,
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $original->id,
        ]);

        $this->assertTrue($original->hasTranslationForLanguage(SupportedLanguage::NL));
        $this->assertFalse($original->hasTranslationForLanguage(SupportedLanguage::DE));
    }

    public function test_get_available_translation_languages(): void
    {
        $original = $this->createDraft([
            'language' => SupportedLanguage::EN->value,
            'draft_type' => DraftType::ORIGINAL->value,
        ]);

        $this->createDraft([
            'language' => SupportedLanguage::NL->value,
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $original->id,
        ]);

        $available = $original->getAvailableTranslationLanguages();

        $this->assertNotContainsEquals(SupportedLanguage::EN, $available);
        $this->assertNotContainsEquals(SupportedLanguage::NL, $available);
        $this->assertContainsEquals(SupportedLanguage::DE, $available);
        $this->assertContainsEquals(SupportedLanguage::FR, $available);
        $this->assertContainsEquals(SupportedLanguage::ES, $available);
    }
}
