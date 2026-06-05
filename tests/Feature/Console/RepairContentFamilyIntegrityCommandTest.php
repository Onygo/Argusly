<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createFamilyIntegrityRepairContext(): array
{
    $user = User::query()->create([
        'name' => 'Family Repair User',
        'email' => 'family-repair-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Family Repair Org',
        'slug' => 'family-repair-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'primary_user_id' => $user->id,
    ]);

    $user->forceFill([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ])->save();

    $workspace = Workspace::query()->create([
        'name' => 'Family Repair Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'nl',
        'enabled_content_languages' => ['nl', 'en'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Family Repair Site',
        'site_url' => 'https://family-repair.example.com',
        'allowed_domains' => ['family-repair.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$workspace, $site, $user];
}

it('repairs mirrored links and duplicate locales by keeping one canonical variant per locale', function () {
    [$workspace, $site] = createFamilyIntegrityRepairContext();

    $source = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Canonical Dutch source',
        'language' => 'nl',
        'family_id' => null,
        'translation_source_locale' => null,
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'publish_status' => 'draft',
        'external_key' => (string) Str::uuid(),
    ]);

    $english = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Canonical English translation',
        'language' => 'en',
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'publish_status' => 'draft',
        'external_key' => (string) Str::uuid(),
    ]);

    $duplicateDutchId = (string) Str::uuid();

    DB::table('contents')->insert([
        'id' => $duplicateDutchId,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Broken Dutch duplicate',
        'language' => 'nl',
        'family_id' => (string) $english->id,
        'translation_source_content_id' => (string) $english->id,
        'translation_source_version_id' => null,
        'translation_source_locale' => 'en',
        'is_source_locale' => 0,
        'translation_generated_at' => now(),
        'translation_source_updated_at' => now(),
        'title' => 'Broken Dutch duplicate',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'external_key' => (string) Str::uuid(),
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'publish_status' => 'draft',
        'created_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ]);

    DB::table('contents')
        ->where('id', $english->id)
        ->update([
            'translation_source_content_id' => $duplicateDutchId,
            'translation_source_locale' => 'nl',
        ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $duplicateDutchId,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Broken Dutch brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $duplicateDutchId,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Broken Dutch draft',
        'language' => 'nl',
        'draft_type' => 'translation',
        'output_type' => 'kb_article',
        'content_html' => '<p>Broken duplicate body.</p>',
    ]);

    $publication = ContentPublication::query()->create([
        'content_id' => $duplicateDutchId,
        'client_site_id' => $site->id,
        'provider' => ContentPublication::PROVIDER_WORDPRESS,
        'locale' => 'nl',
        'delivery_status' => 'pending',
    ]);

    $this->artisan('content:repair-family-integrity --dry-run')
        ->expectsOutputToContain('duplicate locales')
        ->assertExitCode(0);

    $this->artisan('content:repair-family-integrity')
        ->expectsOutputToContain('Repaired 1 family/families.')
        ->assertExitCode(0);

    $source->refresh();
    $english->refresh();
    $duplicateDutch = Content::query()->findOrFail($duplicateDutchId);

    expect((string) $source->family_id)->toBe((string) $source->id)
        ->and((string) $english->family_id)->toBe((string) $source->id)
        ->and((string) $english->translation_source_content_id)->toBe((string) $source->id)
        ->and($source->normalizedLocalizationFamily()->pluck('language.value')->sort()->values()->all())->toBe(['en', 'nl'])
        ->and((string) $duplicateDutch->status)->toBe('archived')
        ->and((string) $duplicateDutch->family_id)->toBe((string) $duplicateDutch->id)
        ->and((string) $draft->fresh()->content_id)->toBe((string) $source->id)
        ->and((string) $brief->fresh()->content_id)->toBe((string) $source->id)
        ->and((string) $publication->fresh()->content_id)->toBe((string) $source->id);
});
