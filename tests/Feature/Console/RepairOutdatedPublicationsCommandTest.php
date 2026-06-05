<?php

use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('repairs false positive outdated translation state after a newer successful publication', function () {
    [$workspace, $site] = createOutdatedPublicationRepairContext();

    $sourceAt = now()->subDay();
    $draftAt = now()->subDays(2);
    $liveAt = now();

    [, $translation] = createOutdatedPublicationRepairFamily($workspace, $site, $sourceAt, $draftAt, $liveAt);

    expect($translation->fresh()->isTranslationOutdated())->toBeTrue();

    $this->artisan('content:repair-outdated-publications')
        ->expectsOutputToContain('Outdated publications: repaired=1 recoverable=0')
        ->assertExitCode(0);

    $translation->refresh();

    expect($translation->isTranslationOutdated())->toBeFalse()
        ->and($translation->translation_source_updated_at?->timestamp)->toBe($sourceAt->getTimestamp());
});

it('does not clear outdated state when the draft is still newer than live', function () {
    [$workspace, $site] = createOutdatedPublicationRepairContext();

    $sourceAt = now();
    $draftAt = now();
    $liveAt = now()->subDay();

    [, $translation] = createOutdatedPublicationRepairFamily($workspace, $site, $sourceAt, $draftAt, $liveAt);
    $baseline = $translation->translation_source_updated_at?->toIso8601String();

    $this->artisan('content:repair-outdated-publications')
        ->expectsOutputToContain('Outdated publications: repaired=0 recoverable=1')
        ->assertExitCode(0);

    $translation->refresh();

    expect($translation->isTranslationOutdated())->toBeTrue()
        ->and($translation->translation_source_updated_at?->toIso8601String())->toBe($baseline);
});

it('supports dry-run without changing outdated baselines', function () {
    [$workspace, $site] = createOutdatedPublicationRepairContext();

    $sourceAt = now()->subDay();
    $draftAt = now()->subDays(2);
    $liveAt = now();

    [, $translation] = createOutdatedPublicationRepairFamily($workspace, $site, $sourceAt, $draftAt, $liveAt);
    $baseline = $translation->translation_source_updated_at?->toIso8601String();

    $this->artisan('content:repair-outdated-publications --dry-run')
        ->expectsOutputToContain('Dry run only. No changes were persisted.')
        ->expectsOutputToContain('Outdated publications: repaired=1 recoverable=0')
        ->assertExitCode(0);

    expect($translation->fresh()->translation_source_updated_at?->toIso8601String())->toBe($baseline);
});

function createOutdatedPublicationRepairContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Outdated Repair Org',
        'slug' => 'outdated-repair-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Outdated Repair BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Outdated Repair Workspace',
        'organization_id' => $organization->id,
        'enabled_content_languages' => ['nl', 'en'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Outdated Repair Site',
        'site_url' => 'https://outdated-repair.example.com',
        'base_url' => 'https://outdated-repair.example.com',
        'allowed_domains' => ['outdated-repair.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$workspace, $site];
}

function createOutdatedPublicationRepairFamily(
    Workspace $workspace,
    ClientSite $site,
    \DateTimeInterface $sourceAt,
    \DateTimeInterface $draftAt,
    \DateTimeInterface $liveAt,
): array {
    $source = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Nederlandse bron',
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'publish_status' => 'published',
    ]);

    DB::table('contents')->where('id', $source->id)->update([
        'updated_at' => $sourceAt,
    ]);

    $translation = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'English translation',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'translation_generated_at' => now()->subDays(3),
        'translation_source_updated_at' => now()->subDays(3),
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'published',
        'source' => 'translation',
        'publish_status' => 'published',
        'delivery_status' => 'delivered',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $translation->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'English repair brief',
        'language' => SupportedLanguage::EN->value,
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $translation->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'English repair draft',
        'language' => SupportedLanguage::EN->value,
        'content_html' => '<h1>English repair draft</h1>',
    ]);

    DB::table('drafts')->where('id', $draft->id)->update([
        'created_at' => $draftAt,
        'updated_at' => $draftAt,
    ]);

    ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $translation->id,
        'client_site_id' => (string) $site->id,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'locale' => SupportedLanguage::EN->value,
        'remote_id' => 'en-article',
        'remote_url' => 'https://outdated-repair.example.com/en/article',
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => $liveAt,
    ]);

    return [$source->fresh(), $translation->fresh()];
}
