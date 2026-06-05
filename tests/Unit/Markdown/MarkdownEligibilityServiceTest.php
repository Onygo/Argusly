<?php

use App\Models\ContentPublication;
use App\Services\Markdown\MarkdownEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks only publishable content states as markdown eligible', function () {
    $service = app(MarkdownEligibilityService::class);

    [, , $draft] = makeMarkdownContent(status: 'draft', publishStatus: 'draft');
    [, , $review] = makeMarkdownContent(status: 'review', publishStatus: 'draft');
    [, , $approved] = makeMarkdownContent(status: 'approved', publishStatus: 'published');
    [, , $published] = makeMarkdownContent(status: 'published', publishStatus: 'published');
    [, , $archived] = makeMarkdownContent(status: 'archived', publishStatus: 'published');

    expect($service->isEligible($draft))->toBeFalse()
        ->and($service->isEligible($review))->toBeFalse()
        ->and($service->isEligible($approved))->toBeTrue()
        ->and($service->isEligible($published))->toBeTrue()
        ->and($service->isEligible($archived))->toBeFalse();
});

it('blocks markdown eligibility for remotely private content', function () {
    $service = app(MarkdownEligibilityService::class);

    [, $site, $content] = makeMarkdownContent(status: 'published', publishStatus: 'published');

    ContentPublication::query()->create([
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'provider' => 'wordpress',
        'remote_status' => 'private',
        'delivery_status' => 'delivered',
    ]);

    $decision = $service->evaluate($content->fresh()->load('publications'));

    expect($decision['eligible'])->toBeFalse()
        ->and($decision['reason'])->toBe('remote_private');
});

it('resolves the artifact locale from content language by default', function () {
    $service = app(MarkdownEligibilityService::class);

    [, , $content] = makeMarkdownContent(language: 'nl', status: 'published', publishStatus: 'published');

    $decision = $service->evaluate($content);

    expect($decision['locale'])->toBe('nl');
});

function makeMarkdownContent(
    string $language = 'en',
    string $status = 'published',
    string $publishStatus = 'published',
    ?string $versionBody = '<p>Canonical body</p>',
    ?string $revisionHtml = '<p>Canonical body</p>'
): array {
    $organization = \App\Models\Organization::query()->create([
        'name' => 'Markdown Org ' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4)),
        'slug' => 'markdown-org-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = \App\Models\Workspace::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'Markdown Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => $language,
        'enabled_content_languages' => [$language],
    ]);

    $site = \App\Models\ClientSite::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Markdown Site',
        'site_url' => 'https://example.test',
        'allowed_domains' => ['example.test'],
        'is_active' => true,
    ]);

    $content = \App\Models\Content::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Markdown Content',
        'language' => $language,
        'type' => 'article',
        'status' => $status,
        'source' => 'api',
        'publish_status' => $publishStatus,
        'delivery_status' => 'pending',
    ]);

    $version = null;
    if ($versionBody !== null) {
        $version = \App\Models\ContentVersion::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content_id' => $content->id,
            'type' => 'draft',
            'body' => $versionBody,
            'source' => 'pl',
        ]);
    }

    $revision = null;
    if ($revisionHtml !== null) {
        $revision = \App\Models\ContentRevision::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content_id' => $content->id,
            'revision_number' => 1,
            'label' => 'R1',
            'content_html' => $revisionHtml,
            'is_active' => true,
        ]);
    }

    $content->update([
        'current_version_id' => $version?->id,
        'current_revision_id' => $revision?->id,
    ]);

    return [$workspace, $site, $content->fresh(['workspace', 'currentVersion', 'currentRevision'])];
}
