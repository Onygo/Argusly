<?php

use App\Models\ContentRenderArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rebuilds markdown artifacts synchronously through the artisan command', function () {
    [, , $content] = makeMarkdownCommandContentForRebuild();

    $this->artisan('publishlayer:markdown:rebuild', [
        '--sync' => true,
        '--content' => $content->id,
    ])->assertExitCode(0);

    $artifact = ContentRenderArtifact::query()
        ->where('content_id', $content->id)
        ->where('markdown_locale', 'en')
        ->first();

    expect($artifact)->not->toBeNull()
        ->and($artifact?->markdown_status)->toBe(ContentRenderArtifact::STATUS_READY)
        ->and($artifact?->rendered_markdown)->toContain('# Markdown Command Content')
        ->and($artifact?->rendered_markdown)->toContain('Command body');
});

function makeMarkdownCommandContentForRebuild(): array
{
    $organization = \App\Models\Organization::query()->create([
        'name' => 'Markdown Command Org ' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4)),
        'slug' => 'markdown-command-org-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = \App\Models\Workspace::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'Command Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en'],
    ]);

    $site = \App\Models\ClientSite::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Command Site',
        'site_url' => 'https://command.test',
        'allowed_domains' => ['command.test'],
        'is_active' => true,
    ]);

    $content = \App\Models\Content::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Markdown Command Content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'publish_status' => 'published',
        'delivery_status' => 'pending',
    ]);

    $version = \App\Models\ContentVersion::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'content_id' => $content->id,
        'type' => 'draft',
        'body' => '<p>Command body</p>',
        'source' => 'pl',
    ]);

    $revision = \App\Models\ContentRevision::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => '<p>Command body</p>',
        'is_active' => true,
    ]);

    $content->update([
        'current_version_id' => $version->id,
        'current_revision_id' => $revision->id,
    ]);

    return [$workspace, $site, $content];
}
