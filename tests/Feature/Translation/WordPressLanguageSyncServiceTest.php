<?php

namespace Tests\Feature\Translation;

use App\Enums\SupportedLanguage;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WordPress\WordPressLanguageSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WordPressLanguageSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_tracks_wordpress_targets_per_localized_content_record(): void
    {
        $user = User::query()->create([
            'name' => 'WP Sync User',
            'email' => 'wp-sync-'.Str::random(8).'@example.com',
            'password' => bcrypt('password'),
        ]);

        $organization = Organization::query()->create([
            'name' => 'WP Sync Org',
            'slug' => 'wp-sync-org-'.Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'primary_user_id' => $user->id,
        ]);

        $user->forceFill([
            'organization_id' => $organization->id,
            'role' => 'owner',
        ])->save();

        $workspace = Workspace::query()->create([
            'name' => 'WP Sync Workspace',
            'organization_id' => $organization->id,
            'default_content_language' => SupportedLanguage::EN->value,
            'enabled_content_languages' => [SupportedLanguage::EN->value, SupportedLanguage::NL->value],
        ]);

        $clientSite = ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => ClientSite::TYPE_WORDPRESS,
            'name' => 'WP Sync Site',
            'site_url' => 'https://wp-sync.example.com',
            'allowed_domains' => ['wp-sync.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);

        $englishContent = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $clientSite->id,
            'title' => 'English content',
            'language' => SupportedLanguage::EN->value,
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
            'delivery_status' => 'pending',
            'publish_status' => 'draft',
        ]);

        $dutchContent = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $clientSite->id,
            'title' => 'Nederlandse content',
            'language' => SupportedLanguage::NL->value,
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
            'delivery_status' => 'pending',
            'publish_status' => 'draft',
        ]);

        $service = app(WordPressLanguageSyncService::class);

        $englishTarget = $service->getOrCreatePublishTarget($englishContent, $clientSite, SupportedLanguage::EN);
        $service->updatePublishTargetAfterSync($englishTarget, [
            'ok' => true,
            'wp_post_id' => '101',
            'remote_permalink' => 'https://wp-sync.example.com/en/english-content',
            'external_key' => 'english-content-en',
        ]);

        $dutchTarget = $service->getOrCreatePublishTarget($dutchContent, $clientSite, SupportedLanguage::NL);
        $service->updatePublishTargetAfterSync($dutchTarget, [
            'ok' => true,
            'wp_post_id' => '202',
            'remote_permalink' => 'https://wp-sync.example.com/nl/nederlandse-content',
            'external_key' => 'nederlandse-content-nl',
        ]);

        $this->assertNotSame((string) $englishTarget->id, (string) $dutchTarget->id);
        $this->assertSame('101', (string) $englishTarget->fresh()->wp_post_id);
        $this->assertSame('202', (string) $dutchTarget->fresh()->wp_post_id);
        $this->assertSame('en', (string) $englishTarget->fresh()->language->value);
        $this->assertSame('nl', (string) $dutchTarget->fresh()->language->value);
    }
}
