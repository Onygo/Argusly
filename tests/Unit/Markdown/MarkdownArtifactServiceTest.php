<?php

use App\Models\ContentRenderArtifact;
use App\Services\Markdown\MarkdownArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('stores a locale-aware pending artifact from canonical content html', function () {
    $service = app(MarkdownArtifactService::class);
    [, , $content] = makeArtifactContent(language: 'en', status: 'published', publishStatus: 'published');

    $artifact = $service->rebuildForContent($content);

    expect($artifact)->toBeInstanceOf(ContentRenderArtifact::class)
        ->and($artifact->markdown_locale->value)->toBe('en')
        ->and($artifact->markdown_status)->toBe(ContentRenderArtifact::STATUS_READY)
        ->and($artifact->markdown_source)->toBe(ContentRenderArtifact::SOURCE_CURRENT_REVISION)
        ->and($artifact->rendered_html)->toContain('<p>Canonical body</p>')
        ->and($artifact->rendered_markdown)->toContain('Canonical body')
        ->and($artifact->markdown_checksum)->not->toBeNull()
        ->and($content->fresh()->hasMarkdown())->toBeTrue()
        ->and($content->fresh()->markdownLocale())->toBe('en');
});

it('stores separate artifacts per locale and exposes content helpers', function () {
    $service = app(MarkdownArtifactService::class);
    [, , $content] = makeArtifactContent(language: 'nl', status: 'published', publishStatus: 'published');

    $service->storeArtifact($content, [
        'markdown_locale' => 'nl',
        'content_version_id' => $content->current_version_id,
        'rendered_html' => '<h1>Hallo</h1><p>Nederlandse tekst</p>',
        'rendered_markdown' => "# Hallo\n\nNederlandse tekst",
        'markdown_status' => ContentRenderArtifact::STATUS_READY,
        'markdown_source' => ContentRenderArtifact::SOURCE_MANUAL,
        'markdown_generated_at' => now(),
    ]);

    $service->storeArtifact($content, [
        'markdown_locale' => 'en',
        'content_version_id' => $content->current_version_id,
        'rendered_html' => '<h1>Hello</h1><p>English text</p>',
        'rendered_markdown' => "# Hello\n\nEnglish text",
        'markdown_status' => ContentRenderArtifact::STATUS_READY,
        'markdown_source' => ContentRenderArtifact::SOURCE_MANUAL,
        'markdown_generated_at' => now(),
    ]);

    $content = $content->fresh('renderArtifacts');

    expect($content->renderArtifacts)->toHaveCount(2)
        ->and($content->hasMarkdown())->toBeTrue()
        ->and($content->markdownLocale())->toBe('nl')
        ->and($content->markdownArtifact('en')?->rendered_markdown)->toContain('Hello')
        ->and($content->markdownChecksum('en'))->not->toBeNull();
});

it('marks a ready artifact stale when requested after a content change', function () {
    Queue::fake();

    $service = app(MarkdownArtifactService::class);
    [, , $content] = makeArtifactContent(language: 'en', status: 'published', publishStatus: 'published');

    $artifact = $service->storeArtifact($content, [
        'markdown_locale' => 'en',
        'content_version_id' => $content->current_version_id,
        'rendered_html' => '<h1>V1</h1><p>Body</p>',
        'rendered_markdown' => "# V1\n\nBody",
        'markdown_status' => ContentRenderArtifact::STATUS_READY,
        'markdown_source' => ContentRenderArtifact::SOURCE_MANUAL,
        'markdown_generated_at' => now(),
    ]);

    $nextVersion = \App\Models\ContentVersion::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'content_id' => $content->id,
        'type' => 'revision',
        'body' => '<h1>V2</h1><p>Changed</p>',
        'source' => 'pl',
    ]);

    $content->update(['current_version_id' => $nextVersion->id]);

    $stale = $service->markStaleForContent($content->fresh(['workspace', 'renderArtifacts']));

    expect($stale->id)->toBe($artifact->id)
        ->and($stale->markdown_status)->toBe(ContentRenderArtifact::STATUS_STALE)
        ->and($stale->rendered_markdown)->toContain('V1');
});

it('regenerates a ready artifact when forced', function () {
    $service = app(MarkdownArtifactService::class);
    [, , $content] = makeArtifactContent(
        language: 'en',
        status: 'published',
        publishStatus: 'published',
        revisionHtml: '<p>Fresh body</p>'
    );

    $artifact = $service->rebuildForContent($content, force: true);

    expect($artifact->markdown_status)->toBe(ContentRenderArtifact::STATUS_READY)
        ->and($artifact->rendered_markdown)->toContain('Fresh body')
        ->and($artifact->markdown_generated_at)->not->toBeNull();
});

function makeArtifactContent(
    string $language = 'en',
    string $status = 'published',
    string $publishStatus = 'published',
    string $versionBody = '<p>Canonical body</p>',
    string $revisionHtml = '<p>Canonical body</p>'
): array {
    $organization = \App\Models\Organization::query()->create([
        'name' => 'Markdown Artifact Org ' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4)),
        'slug' => 'markdown-artifact-org-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = \App\Models\Workspace::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'Artifact Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => $language,
        'enabled_content_languages' => [$language, 'en', 'nl'],
    ]);

    $site = \App\Models\ClientSite::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Artifact Site',
        'site_url' => 'https://artifact.test',
        'allowed_domains' => ['artifact.test'],
        'is_active' => true,
    ]);

    $content = \App\Models\Content::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Markdown Artifact Content',
        'language' => $language,
        'type' => 'article',
        'status' => $status,
        'source' => 'api',
        'publish_status' => $publishStatus,
        'delivery_status' => 'pending',
    ]);

    $version = \App\Models\ContentVersion::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'content_id' => $content->id,
        'type' => 'draft',
        'body' => $versionBody,
        'source' => 'pl',
    ]);

    $revision = \App\Models\ContentRevision::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => $revisionHtml,
        'is_active' => true,
    ]);

    $content->update([
        'current_version_id' => $version->id,
        'current_revision_id' => $revision->id,
    ]);

    return [$workspace, $site, $content->fresh(['workspace', 'currentVersion', 'currentRevision'])];
}
