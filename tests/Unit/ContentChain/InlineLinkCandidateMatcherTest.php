<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentSeries;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContentChain\InlineLinkCandidateMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('matches contextual inline links without breaking existing anchors', function () {
    config()->set('content_chain.suggestions.confidence_threshold', 0.55);
    config()->set('content_chain.inline_links.allow_heading_links', false);
    config()->set('content_chain.inline_links.default_max_links', 2);

    $organization = Organization::query()->create([
        'name' => 'Matcher Org',
        'slug' => 'matcher-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Matcher Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Matcher Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'approved_at' => now(),
        'active' => true,
    ]);

    $series = ContentSeries::query()->create([
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'AI Governance Series',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance',
        'status' => ContentSeries::STATUS_READY,
        'articles_count' => 0,
        'created_by' => $user->id,
    ]);

    $source = matcherContent(
        $workspace,
        $site,
        $series,
        'AI governance overview',
        'ai governance',
        'https://example.com/ai-governance-overview',
        '<p>Teams use an ai governance checklist to define approvals and review steps.</p><p>We already linked <a href="https://example.com/ai-policy-template">AI policy template</a> manually.</p>',
    );
    $inlineTarget = matcherContent(
        $workspace,
        $site,
        $series,
        'AI governance checklist',
        'ai governance checklist',
        'https://example.com/ai-governance-checklist',
        '<p>Checklist body.</p>',
    );
    $footerTarget = matcherContent(
        $workspace,
        $site,
        $series,
        'AI policy template',
        'ai policy template',
        'https://example.com/ai-policy-template',
        '<p>Policy template body.</p>',
    );

    $matches = app(InlineLinkCandidateMatcher::class)->match(
        $source,
        collect([$inlineTarget, $footerTarget]),
        ['source_score' => 78.0]
    );

    expect($matches['inline'])->toHaveCount(1)
        ->and($matches['inline']->first()['anchor_text'])->toBe('ai governance checklist')
        ->and($matches['inline']->first()['placement_type'])->toBe('inline')
        ->and($matches['footer'])->toHaveCount(0);
});

function matcherContent(
    Workspace $workspace,
    ClientSite $site,
    ContentSeries $series,
    string $title,
    string $keyword,
    string $url,
    string $html
): Content {
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'series_id' => $series->id,
        'title' => $title,
        'primary_keyword' => $keyword,
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'publish_status' => 'published',
        'published_url' => $url,
        'external_key' => (string) Str::uuid(),
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => ContentVersion::TYPE_DRAFT,
        'body' => $html,
        'source' => ContentVersion::SOURCE_ARGUSLY,
    ]);

    $revision = ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => $html,
        'is_active' => true,
    ]);

    $content->update([
        'current_version_id' => $version->id,
        'current_revision_id' => $revision->id,
    ]);

    return $content->fresh(['currentRevision', 'currentVersion']);
}
