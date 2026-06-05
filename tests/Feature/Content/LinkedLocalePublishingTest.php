<?php

use App\Enums\SupportedLanguage;
use App\Events\Agents\TranslationCompleted;
use App\Listeners\Content\SyncLinkedLocalePublishingAfterTranslation;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Content\LocalePublishingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware();
});

it('syncs scheduled publish datetime from source to eligible translations', function () {
    [$user, $workspace, $site] = linkedLocalePublishingContext();

    $source = linkedLocaleContent($workspace, $site, 'English source', 'en');
    $readyTranslation = linkedLocaleContent($workspace, $site, 'Dutch translation', 'nl', [
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
    ]);
    linkedLocaleDraft($readyTranslation, $site, 'Dutch translation draft');

    $notReadyTranslation = linkedLocaleContent($workspace, $site, 'French translation', 'fr', [
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
    ]);

    $excludedTranslation = linkedLocaleContent($workspace, $site, 'German translation', 'de', [
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'sync_with_source' => false,
    ]);
    linkedLocaleDraft($excludedTranslation, $site, 'German translation draft');

    $scheduledAt = Carbon::parse('2026-05-03 09:00:00');

    $source->forceFill([
        'scheduled_publish_at' => $scheduledAt,
        'publish_status' => 'scheduled',
    ])->save();

    app(LocalePublishingSyncService::class)->syncSourceSchedule($source->fresh(), $scheduledAt);

    expect($readyTranslation->fresh()->publish_status)->toBe('scheduled')
        ->and($readyTranslation->fresh()->scheduled_publish_at?->toIso8601String())->toBe($scheduledAt->toIso8601String())
        ->and($notReadyTranslation->fresh()->publish_status)->toBe('draft')
        ->and($notReadyTranslation->fresh()->scheduled_publish_at)->toBeNull()
        ->and($excludedTranslation->fresh()->publish_status)->toBe('draft')
        ->and($excludedTranslation->fresh()->scheduled_publish_at)->toBeNull();
});

it('rejects manual scheduling on synced translation locales', function () {
    [$user, $workspace, $site] = linkedLocalePublishingContext();

    $source = linkedLocaleContent($workspace, $site, 'English source', 'en');
    $translation = linkedLocaleContent($workspace, $site, 'Dutch translation', 'nl', [
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'sync_with_source' => true,
    ]);
    linkedLocaleDraft($translation, $site, 'Dutch translation draft');

    $this->actingAs($user)
        ->from(route('app.content.show', $translation))
        ->post(route('app.content.schedule', $translation), [
            'scheduled_publish_at' => now()->addDay()->toDateTimeString(),
        ]);

    expect($translation->fresh()->publish_status)->toBe('draft')
        ->and($translation->fresh()->scheduled_publish_at)->toBeNull();
});

it('auto-publishes a ready translation when the source is already live', function () {
    [, $workspace, $site] = linkedLocalePublishingContext();

    $source = linkedLocaleContent($workspace, $site, 'English source', 'en', [
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    linkedLocaleDraft($source, $site, 'English source draft');

    $translation = linkedLocaleContent($workspace, $site, 'Dutch translation', 'nl', [
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'sync_with_source' => true,
        'auto_publish' => true,
    ]);
    linkedLocaleDraft($translation, $site, 'Dutch translation draft');

    app(SyncLinkedLocalePublishingAfterTranslation::class)->handle(new TranslationCompleted(
        sourceDraftId: (string) Str::uuid(),
        translatedDraftId: (string) Str::uuid(),
        sourceContentId: (string) $source->id,
        translatedContentId: (string) $translation->id,
        targetLocale: 'nl',
    ));

    $translation->refresh();

    expect($translation->publish_status)->toBe('published')
        ->and($translation->status)->toBe('published')
        ->and($translation->scheduled_publish_at)->toBeNull();
});

it('does not sync publishing when a translation has a manual locale override', function () {
    [, $workspace, $site] = linkedLocalePublishingContext();

    $source = linkedLocaleContent($workspace, $site, 'English source', 'en', [
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    linkedLocaleDraft($source, $site, 'English source draft');

    $translation = linkedLocaleContent($workspace, $site, 'Dutch translation', 'nl', [
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'sync_with_source' => false,
        'auto_publish' => true,
    ]);
    linkedLocaleDraft($translation, $site, 'Dutch translation draft');

    app(SyncLinkedLocalePublishingAfterTranslation::class)->handle(new TranslationCompleted(
        sourceDraftId: (string) Str::uuid(),
        translatedDraftId: (string) Str::uuid(),
        sourceContentId: (string) $source->id,
        translatedContentId: (string) $translation->id,
        targetLocale: 'nl',
    ));

    expect($translation->fresh()->publish_status)->toBe('draft')
        ->and($translation->fresh()->status)->toBe('draft');
});

it('does not auto-publish translations with empty draft content', function () {
    [, $workspace, $site] = linkedLocalePublishingContext();

    $source = linkedLocaleContent($workspace, $site, 'English source', 'en', [
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    linkedLocaleDraft($source, $site, 'English source draft');

    $translation = linkedLocaleContent($workspace, $site, 'Dutch translation', 'nl', [
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'sync_with_source' => true,
        'auto_publish' => true,
    ]);
    $emptyDraft = linkedLocaleDraft($translation, $site, 'Dutch translation draft');
    $emptyDraft->forceFill([
        'content_html' => '',
    ])->save();

    app(SyncLinkedLocalePublishingAfterTranslation::class)->handle(new TranslationCompleted(
        sourceDraftId: (string) Str::uuid(),
        translatedDraftId: (string) Str::uuid(),
        sourceContentId: (string) $source->id,
        translatedContentId: (string) $translation->id,
        targetLocale: 'nl',
    ));

    expect($translation->fresh()->publish_status)->toBe('draft')
        ->and($translation->fresh()->status)->toBe('draft');
});

function linkedLocalePublishingContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Linked Locale Org',
        'slug' => 'linked-locale-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Linked Locale BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Linked Locale Workspace',
        'organization_id' => $organization->id,
        'enabled_content_languages' => ['en', 'nl', 'fr', 'de'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Linked Locale Site',
        'site_url' => 'https://linked-locale.example.com',
        'base_url' => 'https://linked-locale.example.com',
        'allowed_domains' => ['linked-locale.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$user, $workspace, $site];
}

function linkedLocaleContent(Workspace $workspace, ClientSite $site, string $title, string $locale, array $overrides = []): Content
{
    return Content::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => $title,
        'language' => SupportedLanguage::fromStringOrDefault($locale)->value,
        'translation_source_locale' => array_key_exists('translation_source_content_id', $overrides) ? 'en' : null,
        'is_source_locale' => ! array_key_exists('translation_source_content_id', $overrides),
        'sync_with_source' => true,
        'auto_publish' => true,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'publish_status' => 'draft',
        'delivery_status' => 'pending',
        'created_by' => 1,
        'updated_by' => 1,
    ], $overrides));
}

function linkedLocaleDraft(Content $content, ClientSite $site, string $title): Draft
{
    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => $title,
        'language' => $content->localeCode(),
        'output_type' => 'kb_article',
    ]);

    return Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready',
        'delivery_status' => 'pending',
        'title' => $title,
        'language' => $content->localeCode(),
        'content_html' => '<p>Ready draft</p>',
    ]);
}
