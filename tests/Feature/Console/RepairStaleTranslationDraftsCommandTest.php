<?php

namespace Tests\Feature\Console;

use App\Enums\DraftType;
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

class RepairStaleTranslationDraftsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_cancels_repairable_stale_translation_drafts_but_keeps_valid_variants(): void
    {
        $user = User::query()->create([
            'name' => 'Console Test User',
            'email' => 'console-test-' . Str::random(8) . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $organization = Organization::query()->create([
            'name' => 'Console Test Org',
            'slug' => 'console-test-org-' . Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'primary_user_id' => $user->id,
        ]);

        $user->forceFill([
            'organization_id' => $organization->id,
            'role' => 'owner',
        ])->save();

        $workspace = Workspace::query()->create([
            'name' => 'Console Test Workspace',
            'organization_id' => $organization->id,
            'default_content_language' => 'nl',
            'enabled_content_languages' => ['nl', 'en'],
        ]);

        $clientSite = ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Console Test Site',
            'site_url' => 'https://console-test.example.com',
            'allowed_domains' => ['console-test.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);

        $sourceContent = Content::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $clientSite->id,
            'title' => 'Nederlandse bron',
            'status' => 'draft',
            'language' => 'nl',
            'translation_source_locale' => 'nl',
            'is_source_locale' => true,
            'publish_status' => 'draft',
        ]);

        $sourceBrief = Brief::query()->create([
            'client_site_id' => $clientSite->id,
            'content_id' => $sourceContent->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Nederlandse brief',
            'language' => 'nl',
            'content_type' => 'blog',
            'output_type' => 'kb_article',
        ]);

        $sourceDraft = Draft::query()->create([
            'brief_id' => $sourceBrief->id,
            'content_id' => $sourceContent->id,
            'client_site_id' => $clientSite->id,
            'status' => 'ready',
            'title' => 'Nederlandse bron draft',
            'language' => 'nl',
            'draft_type' => DraftType::ORIGINAL->value,
            'output_type' => 'kb_article',
            'content_html' => '<p>Broncontent.</p>',
        ]);

        $orphanBrief = Brief::query()->create([
            'client_site_id' => $clientSite->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Broken EN brief',
            'language' => 'en',
            'content_type' => 'blog',
            'output_type' => 'kb_article',
        ]);

        $staleDraft = Draft::query()->create([
            'brief_id' => $orphanBrief->id,
            'content_id' => null,
            'client_site_id' => $clientSite->id,
            'status' => 'ready',
            'title' => 'Broken EN translation',
            'language' => 'en',
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $sourceDraft->id,
            'translation_source_language' => 'nl',
            'output_type' => 'kb_article',
            'content_html' => '<p>Broken translation.</p>',
        ]);

        $translatedContent = Content::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $clientSite->id,
            'title' => 'Valid English variant',
            'status' => 'draft',
            'language' => 'en',
            'translation_source_content_id' => $sourceContent->id,
            'translation_source_locale' => 'nl',
            'is_source_locale' => false,
            'publish_status' => 'draft',
        ]);

        $translatedBrief = Brief::query()->create([
            'client_site_id' => $clientSite->id,
            'content_id' => $translatedContent->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Valid EN brief',
            'language' => 'en',
            'content_type' => 'blog',
            'output_type' => 'kb_article',
        ]);

        $validDraft = Draft::query()->create([
            'brief_id' => $translatedBrief->id,
            'content_id' => $translatedContent->id,
            'client_site_id' => $clientSite->id,
            'status' => 'ready',
            'title' => 'Valid EN translation',
            'language' => 'en',
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $sourceDraft->id,
            'translation_source_language' => 'nl',
            'output_type' => 'kb_article',
            'content_html' => '<p>Valid translation.</p>',
        ]);

        $this->artisan('translation:repair-stale-drafts', [
            '--source-draft-id' => (string) $sourceDraft->id,
            '--target-locale' => 'en',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('cancelled', (string) $staleDraft->fresh()->status);
        $this->assertSame('ready', (string) $validDraft->fresh()->status);
        $this->assertSame(
            'stale_duplicate_translation_candidate',
            (string) data_get($staleDraft->fresh()->meta, 'translation_cleanup.reason')
        );
    }
}
