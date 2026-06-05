<?php

namespace Tests\Feature\Translation;

use App\Enums\SupportedLanguage;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceLanguageSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;
    protected Workspace $workspace;

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
        ]);
    }

    public function test_workspace_default_content_language_defaults_to_english(): void
    {
        // Create workspace without specifying default_content_language
        // to verify the database default ('en') is applied
        $workspace = Workspace::query()->create([
            'name' => 'Test Workspace 2',
            'organization_id' => $this->organization->id,
        ]);

        $this->assertSame(SupportedLanguage::EN, $workspace->default_content_language);
    }

    public function test_workspace_stores_default_content_language(): void
    {
        $this->workspace->default_content_language = SupportedLanguage::NL->value;
        $this->workspace->save();
        $this->workspace->refresh();

        $this->assertSame(SupportedLanguage::NL, $this->workspace->default_content_language);
    }

    public function test_workspace_enabled_content_languages_defaults_to_english(): void
    {
        $workspace = Workspace::query()->create([
            'name' => 'Test Workspace 3',
            'organization_id' => $this->organization->id,
            'enabled_content_languages' => null,
        ]);

        $enabled = $workspace->enabled_content_languages;

        $this->assertContains('en', $enabled);
    }

    public function test_workspace_stores_enabled_content_languages(): void
    {
        $this->workspace->enabled_content_languages = [
            SupportedLanguage::EN->value,
            SupportedLanguage::NL->value,
            SupportedLanguage::DE->value,
        ];
        $this->workspace->save();
        $this->workspace->refresh();

        $enabled = $this->workspace->enabled_content_languages;

        $this->assertContains('en', $enabled);
        $this->assertContains('nl', $enabled);
        $this->assertContains('de', $enabled);
        $this->assertCount(3, $enabled);
    }

    public function test_workspace_is_language_enabled_returns_correctly(): void
    {
        $this->workspace->enabled_content_languages = [
            SupportedLanguage::EN->value,
            SupportedLanguage::NL->value,
        ];
        $this->workspace->save();

        $this->assertTrue($this->workspace->isLanguageEnabled(SupportedLanguage::EN));
        $this->assertTrue($this->workspace->isLanguageEnabled(SupportedLanguage::NL));
        $this->assertFalse($this->workspace->isLanguageEnabled(SupportedLanguage::DE));
        $this->assertFalse($this->workspace->isLanguageEnabled(SupportedLanguage::FR));
    }

    public function test_workspace_enable_language_adds_language(): void
    {
        $this->workspace->enabled_content_languages = [SupportedLanguage::EN->value];
        $this->workspace->save();

        $this->workspace->enableLanguage(SupportedLanguage::NL);

        $this->assertTrue($this->workspace->isLanguageEnabled(SupportedLanguage::NL));
    }

    public function test_workspace_enable_language_does_not_duplicate(): void
    {
        $this->workspace->enabled_content_languages = [
            SupportedLanguage::EN->value,
            SupportedLanguage::NL->value,
        ];
        $this->workspace->save();

        $this->workspace->enableLanguage(SupportedLanguage::NL);

        $enabled = $this->workspace->enabled_content_languages;
        $nlCount = count(array_filter($enabled, fn ($code) => $code === 'nl'));

        $this->assertSame(1, $nlCount);
    }

    public function test_workspace_disable_language_removes_language(): void
    {
        $this->workspace->enabled_content_languages = [
            SupportedLanguage::EN->value,
            SupportedLanguage::NL->value,
        ];
        $this->workspace->default_content_language = SupportedLanguage::EN->value;
        $this->workspace->save();

        $this->workspace->disableLanguage(SupportedLanguage::NL);

        $this->assertFalse($this->workspace->isLanguageEnabled(SupportedLanguage::NL));
        $this->assertTrue($this->workspace->isLanguageEnabled(SupportedLanguage::EN));
    }

    public function test_workspace_cannot_disable_default_language(): void
    {
        $this->workspace->enabled_content_languages = [
            SupportedLanguage::EN->value,
            SupportedLanguage::NL->value,
        ];
        $this->workspace->default_content_language = SupportedLanguage::EN->value;
        $this->workspace->save();

        $this->workspace->disableLanguage(SupportedLanguage::EN);

        $this->assertTrue($this->workspace->isLanguageEnabled(SupportedLanguage::EN));
    }

    public function test_workspace_get_translation_target_languages(): void
    {
        $this->workspace->enabled_content_languages = [
            SupportedLanguage::EN->value,
            SupportedLanguage::NL->value,
            SupportedLanguage::DE->value,
        ];
        $this->workspace->save();

        $targets = $this->workspace->getTranslationTargetLanguages(SupportedLanguage::EN);

        $this->assertCount(2, $targets);
        $this->assertContainsEquals(SupportedLanguage::NL, $targets);
        $this->assertContainsEquals(SupportedLanguage::DE, $targets);
        $this->assertNotContainsEquals(SupportedLanguage::EN, $targets);
    }

    public function test_workspace_get_enabled_languages_as_enums(): void
    {
        $this->workspace->enabled_content_languages = [
            SupportedLanguage::EN->value,
            SupportedLanguage::NL->value,
        ];
        $this->workspace->save();

        $enums = $this->workspace->getEnabledLanguagesAsEnums();

        $this->assertCount(2, $enums);
        $this->assertContainsEquals(SupportedLanguage::EN, $enums);
        $this->assertContainsEquals(SupportedLanguage::NL, $enums);
    }
}
