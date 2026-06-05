<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\InternalLinking\InternalLinkingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('repairs wrong locale article links to the canonical target for the source locale', function () {
    Queue::fake();

    [$workspace, $site] = makeInternalLinkRepairContext('repair-locale-links', 'laravel');

    $targetNl = makeRepairContent(
        workspace: $workspace,
        site: $site,
        title: 'Juiste Nederlandse gids',
        locale: 'nl',
        publishedUrl: 'https://repair-locale-links.example.com/nl/blog/juiste-nederlandse-gids',
    );

    $targetEn = makeRepairContent(
        workspace: $workspace,
        site: $site,
        title: 'Old English guide',
        locale: 'en',
        publishedUrl: 'https://repair-locale-links.example.com/en/blog/old-english-guide',
        familyId: (string) $targetNl->id,
        translationSourceContentId: (string) $targetNl->id,
        isSourceLocale: false,
        translationSourceLocale: 'nl',
    );

    $sourceNl = makeRepairContent(
        workspace: $workspace,
        site: $site,
        title: 'Nederlands bronartikel',
        locale: 'nl',
        publishedUrl: 'https://repair-locale-links.example.com/nl/blog/nederlands-bronartikel',
    );

    attachCurrentHtml(
        $sourceNl,
        '<p>Lees <a href="' . $targetEn->published_url . '">deze gids</a> voor meer context.</p>'
    );

    $this->artisan('content:repair-internal-links', [
        '--content' => $sourceNl->id,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('wrong-locale-target');

    $sourceNl->refresh()->load('currentRevision', 'currentVersion');

    expect((string) $sourceNl->currentRevision?->content_html)->toContain((string) $targetNl->published_url)
        ->and((string) $sourceNl->currentVersion?->body)->toContain((string) $targetNl->published_url)
        ->and((string) $sourceNl->currentVersion?->body)->not->toContain((string) $targetEn->published_url);
});

it('supports dry run and removes unresolved publication links without dropping anchor text', function () {
    Queue::fake();

    [$workspace, $site] = makeInternalLinkRepairContext('repair-unresolved-links', 'laravel');

    $source = makeRepairContent(
        workspace: $workspace,
        site: $site,
        title: 'Artikel met oude links',
        locale: 'nl',
        publishedUrl: 'https://repair-unresolved-links.example.com/nl/blog/artikel-met-oude-links',
    );

    $originalHtml = '<p>Lees <a href="https://repair-unresolved-links.example.com/nl/blog/verdwenen-artikel">dit artikel</a> nu.</p>';
    attachCurrentHtml($source, $originalHtml);

    $this->artisan('content:repair-internal-links', [
        '--content' => $source->id,
        '--remove-unresolved' => true,
        '--dry-run' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Dry run only. No changes were persisted.');

    expect((string) $source->fresh()->currentVersion?->body)->toBe($originalHtml);

    $this->artisan('content:repair-internal-links', [
        '--content' => $source->id,
        '--remove-unresolved' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('unresolved-target');

    $source->refresh()->load('currentRevision', 'currentVersion');

    expect((string) $source->currentVersion?->body)->toContain('dit artikel')
        ->and((string) $source->currentVersion?->body)->not->toContain('<a ')
        ->and((string) $source->currentRevision?->content_html)->not->toContain('<a ');
});

it('replaces stale publication urls with the current canonical publication url', function () {
    Queue::fake();

    [$workspace, $site] = makeInternalLinkRepairContext('repair-stale-publication', 'laravel');

    $target = makeRepairContent(
        workspace: $workspace,
        site: $site,
        title: 'Canoniek doel',
        locale: 'nl',
        publishedUrl: 'https://repair-stale-publication.example.com/nl/blog/canoniek-doel',
    );

    ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $target->id,
        'client_site_id' => $site->id,
        'locale' => 'nl',
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_url' => 'https://repair-stale-publication.example.com/nl/blog/oud-doel',
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'last_delivered_at' => now()->subDays(2),
    ]);

    $canonicalPublication = ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $target->id,
        'client_site_id' => $site->id,
        'locale' => 'nl',
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_url' => (string) $target->published_url,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'last_delivered_at' => now(),
    ]);

    $source = makeRepairContent(
        workspace: $workspace,
        site: $site,
        title: 'Bronartikel',
        locale: 'nl',
        publishedUrl: 'https://repair-stale-publication.example.com/nl/blog/bronartikel',
    );

    attachCurrentHtml(
        $source,
        '<p>Bekijk <a href="https://repair-stale-publication.example.com/nl/blog/oud-doel">dit artikel</a> daarna.</p>'
    );

    $this->artisan('content:repair-internal-links', [
        '--content' => $source->id,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('stale-publication');

    $source->refresh()->load('currentVersion');

    expect((string) $source->currentVersion?->body)->toContain((string) $canonicalPublication->remote_url)
        ->and((string) $source->currentVersion?->body)->not->toContain('/oud-doel');
});

it('reruns internal linking only when a synced draft can safely be reused', function () {
    Queue::fake();

    [$workspace, $site] = makeInternalLinkRepairContext('repair-rerun-linking', 'laravel');

    $source = makeRepairContent(
        workspace: $workspace,
        site: $site,
        title: 'Bronartikel met draft',
        locale: 'nl',
        publishedUrl: 'https://repair-rerun-linking.example.com/nl/blog/bronartikel-met-draft',
    );

    $html = '<p>Lees <a href="https://repair-rerun-linking.example.com/nl/blog/verdwenen-artikel">dit artikel</a> en plan daarna een nieuwe link.</p>';
    attachCurrentHtml($source, $html);
    attachDraftHtml($source, $site, $html);

    $this->mock(InternalLinkingService::class, function (MockInterface $mock) use ($source): void {
        $mock->shouldReceive('generateForContent')
            ->once()
            ->withArgs(fn (Content $content): bool => (string) $content->id === (string) $source->id)
            ->andReturn([
                'suggestions' => [],
                'applied_suggestions' => [],
                'applied_count' => 0,
                'updated' => false,
            ]);
    });

    $this->artisan('content:repair-internal-links', [
        '--content' => $source->id,
        '--remove-unresolved' => true,
        '--rerun-linking' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Internal linking rerun: requested=1 executed=1');
});

function makeInternalLinkRepairContext(string $prefix, string $siteType = 'laravel'): array
{
    $organization = Organization::query()->create([
        'name' => 'Repair Links Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Repair Links Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => $siteType,
        'name' => 'Repair Links Site',
        'site_url' => 'https://' . $prefix . '.example.com',
        'base_url' => 'https://' . $prefix . '.example.com',
        'allowed_domains' => [$prefix . '.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$workspace, $site];
}

function makeRepairContent(
    Workspace $workspace,
    ClientSite $site,
    string $title,
    string $locale,
    string $publishedUrl,
    ?string $familyId = null,
    ?string $translationSourceContentId = null,
    bool $isSourceLocale = true,
    ?string $translationSourceLocale = null,
): Content {
    return Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => $title,
        'language' => $locale,
        'family_id' => $familyId,
        'translation_source_content_id' => $translationSourceContentId,
        'translation_source_locale' => $translationSourceLocale ?: $locale,
        'is_source_locale' => $isSourceLocale,
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'published_url' => $publishedUrl,
    ]);
}

function attachCurrentHtml(Content $content, string $html): void
{
    $revision = ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'draft_id' => null,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => $html,
        'meta' => [],
        'is_active' => true,
        'created_by_user_id' => null,
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => ContentVersion::TYPE_REVISION,
        'parent_version_id' => null,
        'body' => $html,
        'meta' => [],
        'source' => ContentVersion::SOURCE_PUBLISHLAYER,
        'created_by' => null,
    ]);

    $content->update([
        'current_revision_id' => $revision->id,
        'current_version_id' => $version->id,
    ]);
}

function attachDraftHtml(Content $content, ClientSite $site, string $html): Draft
{
    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'ready',
        'source' => 'manual',
        'title' => $content->title,
        'language' => $content->localeCode(),
        'content_type' => 'article',
        'output_type' => 'kb_article',
        'primary_keyword' => Str::slug((string) $content->title),
    ]);

    return Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'delivery_status' => 'pending',
        'title' => $content->title,
        'output_type' => 'kb_article',
        'language' => $content->localeCode(),
        'content_html' => $html,
        'links' => [],
    ]);
}
