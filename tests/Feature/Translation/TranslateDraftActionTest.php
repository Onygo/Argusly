<?php

namespace Tests\Feature\Translation;

use App\Actions\Drafts\TranslateDraftAction;
use App\Enums\SupportedLanguage;
use App\Jobs\TranslateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TranslateDraftActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_translations_from_the_original_source_draft(): void
    {
        Queue::fake();

        $user = User::query()->create([
            'name' => 'Action Test User',
            'email' => 'translate-action-'.Str::random(8).'@example.com',
            'password' => bcrypt('password'),
        ]);

        $organization = Organization::query()->create([
            'name' => 'Translate Action Org',
            'slug' => 'translate-action-org-'.Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'primary_user_id' => $user->id,
        ]);

        $user->forceFill([
            'organization_id' => $organization->id,
            'role' => 'owner',
        ])->save();

        $workspace = Workspace::query()->create([
            'name' => 'Translate Action Workspace',
            'organization_id' => $organization->id,
            'default_content_language' => SupportedLanguage::EN->value,
            'enabled_content_languages' => [SupportedLanguage::EN->value, SupportedLanguage::NL->value, SupportedLanguage::DE->value],
        ]);

        $clientSite = ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => ClientSite::TYPE_WORDPRESS,
            'name' => 'Translate Action Site',
            'site_url' => 'https://translate-action.example.com',
            'allowed_domains' => ['translate-action.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);

        $originalContent = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $clientSite->id,
            'title' => 'Original content',
            'language' => SupportedLanguage::EN->value,
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
            'delivery_status' => 'pending',
            'publish_status' => 'draft',
        ]);

        $originalBrief = Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $clientSite->id,
            'content_id' => $originalContent->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Original brief',
            'language' => SupportedLanguage::EN->value,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
        ]);

        $originalDraft = Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => $originalBrief->id,
            'content_id' => $originalContent->id,
            'client_site_id' => $clientSite->id,
            'status' => 'ready',
            'title' => 'Original draft',
            'language' => SupportedLanguage::EN->value,
            'draft_type' => 'original',
            'output_type' => 'kb_article',
            'content_html' => '<h1>Original</h1><p>Source draft.</p>',
        ]);

        $translatedContent = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $clientSite->id,
            'title' => 'Vertaalde content',
            'language' => SupportedLanguage::NL->value,
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
            'delivery_status' => 'pending',
            'publish_status' => 'draft',
        ]);

        $translatedBrief = Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $clientSite->id,
            'content_id' => $translatedContent->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Translated brief',
            'language' => SupportedLanguage::NL->value,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
        ]);

        $translatedDraft = Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => $translatedBrief->id,
            'content_id' => $translatedContent->id,
            'client_site_id' => $clientSite->id,
            'status' => 'ready',
            'title' => 'Translated draft',
            'language' => SupportedLanguage::NL->value,
            'draft_type' => 'translation',
            'source_draft_id' => $originalDraft->id,
            'translation_source_language' => SupportedLanguage::EN->value,
            'output_type' => 'kb_article',
            'content_html' => '<h1>Vertaling</h1><p>Translated draft.</p>',
        ]);

        $operation = app(TranslateDraftAction::class)->execute(
            draft: $translatedDraft,
            targetLanguage: SupportedLanguage::DE->value,
        );

        $this->assertSame((string) $originalDraft->id, (string) $operation->resource_id);
        $this->assertSame((string) $translatedDraft->id, (string) data_get($operation->request_payload, 'requested_from_draft_id'));
        $this->assertSame((string) $originalDraft->id, (string) data_get($operation->request_payload, 'source_draft_id'));

        Queue::assertPushed(TranslateDraftJob::class, function (TranslateDraftJob $job) use ($originalDraft): bool {
            return $job->sourceDraftId === (string) $originalDraft->id
                && $job->targetLanguage === SupportedLanguage::DE->value;
        });
    }
}
