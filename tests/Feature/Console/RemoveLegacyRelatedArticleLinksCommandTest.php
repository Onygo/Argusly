<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('removes legacy related article placeholders from stored content records', function () {
    $workspace = legacyRelatedLinksWorkspace();
    $site = legacyRelatedLinksSite($workspace);
    $content = legacyRelatedLinksContent($workspace, $site);
    $html = legacyRelatedLinksHtml();

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'status' => 'ready',
        'title' => 'Legacy related links',
        'language' => 'en',
        'output_type' => 'blog',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'status' => 'generated',
        'title' => 'Legacy related links',
        'language' => 'en',
        'output_type' => 'blog',
        'content_html' => '<p>Clean first.</p>',
    ]);
    Draft::query()->whereKey($draft->id)->update(['content_html' => $html]);

    $revision = ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'draft_id' => (string) $draft->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => $html,
        'is_active' => true,
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => ContentVersion::TYPE_PUBLISHED_SNAPSHOT,
        'body' => $html,
        'source' => ContentVersion::SOURCE_PUBLISHLAYER,
    ]);

    $this->artisan('content:remove-legacy-related-links', ['--content' => (string) $content->id])
        ->expectsOutputToContain('changed=3')
        ->assertExitCode(0);

    expect((string) $draft->fresh()->content_html)
        ->toContain('Article body stays.')
        ->not->toContain('To go deeper')
        ->not->toContain('Related article 1')
        ->and((string) $revision->fresh()->content_html)
        ->toContain('Article body stays.')
        ->not->toContain('Related article 2')
        ->and((string) $version->fresh()->body)
        ->toContain('Article body stays.')
        ->not->toContain('Related reading:');
});

it('keeps manual meaningful related links while cleaning drafts on assignment', function () {
    $draft = new Draft();
    $draft->content_html = '<p>Body with <a href="/en/blog/semantic-seo">semantic SEO guide</a>.</p>'
        . '<p><strong>Related reading:</strong> <a href="/en/blog/one">Related article 1</a></p>';

    expect((string) $draft->content_html)
        ->toContain('semantic SEO guide')
        ->not->toContain('Related article 1');
});

function legacyRelatedLinksWorkspace(): Workspace
{
    $organization = Organization::query()->create([
        'name' => 'Legacy Related Links Org',
        'slug' => 'legacy-related-links-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Legacy Related Links Workspace',
        'organization_id' => (string) $organization->id,
    ]);
}

function legacyRelatedLinksSite(Workspace $workspace): ClientSite
{
    return ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Legacy Related Links Site',
        'site_url' => 'https://legacy-related-links.example.test',
        'base_url' => 'https://legacy-related-links.example.test',
        'allowed_domains' => ['legacy-related-links.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);
}

function legacyRelatedLinksContent(Workspace $workspace, ClientSite $site): Content
{
    return Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Legacy related links',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);
}

function legacyRelatedLinksHtml(): string
{
    return '<p>Article body stays.</p>'
        . '<p>To go deeper into building a resilient content engine and structuring your site for semantic SEO, explore these resources:</p>'
        . '<ul><li><a href="/en/blog/one">Related article 1</a></li><li><a href="/en/blog/two">Related article 2</a></li></ul>'
        . '<p><strong>Related reading:</strong> <a href="/en/blog/one">Related article 1</a> · <a href="/en/blog/two">Related article 2</a></p>';
}
