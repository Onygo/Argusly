<?php

use App\Enums\DraftType;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createLocaleRepairContext(): array
{
    $user = User::query()->create([
        'name' => 'Locale Repair User',
        'email' => 'locale-repair-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Locale Repair Org',
        'slug' => 'locale-repair-org-' . Str::random(6),
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
        'name' => 'Locale Repair Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => SupportedLanguage::NL->value,
        'enabled_content_languages' => [SupportedLanguage::NL->value, SupportedLanguage::EN->value],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Locale Repair Site',
        'site_url' => 'https://locale-repair.example.com',
        'allowed_domains' => ['locale-repair.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'locale-repair-plan'],
        [
            'name' => 'Locale Repair Plan',
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

    return [$workspace, $site, $user];
}

it('repairs stale dutch content that was stored as english', function () {
    [$workspace, $site] = createLocaleRepairContext();

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Nederlandse handleiding voor teams',
        'status' => 'draft',
        'language' => SupportedLanguage::EN->value,
        'translation_source_locale' => SupportedLanguage::EN->value,
        'is_source_locale' => true,
        'publish_status' => 'draft',
    ]);

    $version = ContentVersion::query()->create([
        'content_id' => $content->id,
        'type' => 'revision',
        'body' => '<p>Dit is een Nederlandse uitleg voor teams en processen.</p>',
        'meta' => [
            'locale' => SupportedLanguage::NL->value,
            'excerpt' => 'Nederlandse uitleg voor teams.',
        ],
        'source' => 'pl',
    ]);

    $content->forceFill([
        'current_version_id' => (string) $version->id,
    ])->save();

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Nederlandse brief',
        'language' => SupportedLanguage::EN->value,
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Nederlandse draft',
        'language' => SupportedLanguage::EN->value,
        'draft_type' => DraftType::ORIGINAL->value,
        'output_type' => 'kb_article',
        'content_html' => '<p>Dit is Nederlandse broncontent.</p>',
        'meta' => [
            'language' => SupportedLanguage::EN->value,
        ],
    ]);

    $this->artisan('content:repair-locales --dry-run')
        ->expectsOutputToContain((string) $content->id)
        ->assertExitCode(0);

    expect($content->fresh()->localeCode())->toBe('en')
        ->and((string) $brief->fresh()->language)->toBe('en')
        ->and($draft->fresh()->language)->toBe(SupportedLanguage::EN);

    $this->artisan('content:repair-locales')
        ->expectsOutputToContain('Applied 1 content locale repair(s).')
        ->assertExitCode(0);

    expect($content->fresh()->localeCode())->toBe('nl')
        ->and($content->fresh()->translation_source_locale)->toBeNull()
        ->and((bool) $content->fresh()->is_source_locale)->toBeTrue()
        ->and((string) $brief->fresh()->language)->toBe('nl')
        ->and($draft->fresh()->language)->toBe(SupportedLanguage::NL)
        ->and((string) data_get($draft->fresh()->meta, 'language'))->toBe('nl');
});

it('repairs translation metadata without breaking the source relation', function () {
    [$workspace, $site] = createLocaleRepairContext();

    $source = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Nederlandse bron',
        'status' => 'draft',
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => null,
        'is_source_locale' => true,
        'publish_status' => 'draft',
    ]);

    $translation = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'English translation',
        'status' => 'draft',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => null,
        'is_source_locale' => true,
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'content_id' => $translation->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'English brief',
        'language' => SupportedLanguage::EN->value,
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'brief_id' => $brief->id,
        'content_id' => $translation->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'English translation draft',
        'language' => SupportedLanguage::EN->value,
        'draft_type' => DraftType::TRANSLATION->value,
        'output_type' => 'kb_article',
        'content_html' => '<p>This is the English translation.</p>',
        'meta' => [
            'language' => SupportedLanguage::EN->value,
        ],
    ]);

    $this->artisan('content:repair-locales')->assertExitCode(0);

    $translation->refresh();

    expect($translation->localeCode())->toBe('en')
        ->and((string) $translation->translation_source_content_id)->toBe((string) $source->id)
        ->and((string) $translation->translation_source_locale)->toBe('nl')
        ->and((bool) $translation->is_source_locale)->toBeFalse();
});
