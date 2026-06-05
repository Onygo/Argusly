<?php

use App\Services\Markdown\MarkdownRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders a deterministic basic article markdown snapshot', function () {
    $renderer = app(MarkdownRenderer::class);
    [, , $content] = makeRendererContent(
        language: 'en',
        revisionHtml: '<h1>Deterministic article</h1><p>This is the intro.</p><h2>Benefits</h2><p>Useful body copy.</p>'
    );

    $rendered = $renderer->render($content);

    expect($rendered['locale'])->toBe('en')
        ->and($rendered['rendered_markdown'])->toStartWith("# Deterministic article\n\nThis is the intro.")
        ->and($rendered['rendered_markdown'])->toContain('## Benefits')
        ->and($rendered['rendered_markdown'])->not->toContain('### Benefits')
        ->and($rendered['rendered_markdown'])->toContain('- Locale: en');
});

it('renders structured faq data into a dedicated markdown section', function () {
    $renderer = app(MarkdownRenderer::class);
    [, , $content] = makeRendererContent(
        revisionHtml: '<p>Main body.</p>',
        revisionMeta: [
            'faqs' => [
                ['question' => 'What does PublishLayer do?', 'answer' => '<p>It manages publishable AI content.</p>'],
                ['question' => 'Does it support connectors?', 'answer' => '<p>Yes, through connected destinations.</p>'],
            ],
        ],
    );

    $rendered = $renderer->render($content);

    expect($rendered['rendered_markdown'])->toContain('## Frequently Asked Questions')
        ->and($rendered['rendered_markdown'])->toContain('### What does PublishLayer do?')
        ->and($rendered['rendered_markdown'])->toContain('It manages publishable AI content.');
});

it('preserves heading hierarchy, lists, tables, and strips ui artifacts', function () {
    $renderer = app(MarkdownRenderer::class);
    [, , $content] = makeRendererContent(
        revisionHtml: <<<'HTML'
<div class="cookie-banner"><p>We use cookies.</p></div>
<p>Overview text.</p>
<h2>Checklist</h2>
<ul><li>First item</li><li>Second item</li></ul>
<h3>Comparison</h3>
<table>
    <thead><tr><th>Plan</th><th>Credits</th></tr></thead>
    <tbody><tr><td>Growth</td><td>500</td></tr></tbody>
</table>
HTML
    );

    $rendered = $renderer->render($content);

    expect($rendered['rendered_markdown'])->toContain('## Checklist')
        ->and($rendered['rendered_markdown'])->toContain('### Comparison')
        ->and($rendered['rendered_markdown'])->toContain('- First item')
        ->and($rendered['rendered_markdown'])->toContain('| Plan | Credits |')
        ->and($rendered['rendered_markdown'])->not->toContain('We use cookies.');
});

it('renders locale aware multilingual markdown output', function () {
    $renderer = app(MarkdownRenderer::class);
    [, , $content] = makeRendererContent(
        language: 'nl',
        title: 'Nederlandse gids',
        revisionHtml: '<p>Dit is de intro.</p><h2>Samenvatting</h2><p>Lokale inhoud.</p>'
    );

    $rendered = $renderer->render($content);

    expect($rendered['locale'])->toBe('nl')
        ->and($rendered['rendered_markdown'])->toContain('# Nederlandse gids')
        ->and($rendered['rendered_markdown'])->toContain('- Locale: nl')
        ->and($rendered['rendered_markdown'])->toContain('## Samenvatting');
});

it('renders structured answer blocks into answer-first markdown sections', function () {
    $renderer = app(MarkdownRenderer::class);
    [, , $content] = makeRendererContent(
        revisionHtml: '<p>Supporting body.</p>'
    );

    \App\Models\StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is PublishLayer AEO?',
        'answer' => 'PublishLayer AEO is a scoring layer for answer-first content visibility.',
        'entities' => ['PublishLayer', 'ChatGPT'],
        'order' => 0,
    ]);

    $rendered = $renderer->render($content->fresh(['workspace', 'currentVersion', 'currentRevision', 'seo', 'teamMember', 'answerBlocks']));

    expect($rendered['rendered_markdown'])->toContain('## Answer')
        ->and($rendered['rendered_markdown'])->toContain('## Key Questions')
        ->and($rendered['rendered_markdown'])->toContain('### What is PublishLayer AEO?')
        ->and($rendered['rendered_markdown'])->toContain('PublishLayer AEO is a scoring layer for answer-first content visibility.');
});

function makeRendererContent(
    string $language = 'en',
    string $title = 'Deterministic article',
    string $status = 'published',
    string $publishStatus = 'published',
    string $revisionHtml = '<p>Default body.</p>',
    string $versionBody = '<p>Default body.</p>',
    array $revisionMeta = [],
    array $versionMeta = []
): array {
    $organization = \App\Models\Organization::query()->create([
        'name' => 'Markdown Render Org ' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4)),
        'slug' => 'markdown-render-org-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $teamMember = \App\Models\TeamMember::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Jamie Architect',
        'role' => 'Content Strategist',
        'is_active' => true,
    ]);

    $workspace = \App\Models\Workspace::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'Renderer Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => $language,
        'enabled_content_languages' => [$language, 'en', 'nl'],
    ]);

    $site = \App\Models\ClientSite::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Renderer Site',
        'site_url' => 'https://renderer.test',
        'allowed_domains' => ['renderer.test'],
        'is_active' => true,
    ]);

    $content = \App\Models\Content::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => $title,
        'language' => $language,
        'type' => 'article',
        'status' => $status,
        'source' => 'api',
        'publish_status' => $publishStatus,
        'delivery_status' => 'pending',
        'seo_meta_description' => $language === 'nl' ? 'Dit is de intro.' : 'This is the intro.',
        'team_member_id' => $teamMember->id,
    ]);

    \App\Models\ContentSeo::query()->create([
        'content_id' => $content->id,
        'meta_title' => $title,
        'meta_description' => $language === 'nl' ? 'Dit is de intro.' : 'This is the intro.',
        'primary_keyword' => $language === 'nl' ? 'markdown gids' : 'markdown guide',
    ]);

    $version = \App\Models\ContentVersion::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'content_id' => $content->id,
        'type' => 'draft',
        'body' => $versionBody,
        'meta' => $versionMeta,
        'source' => 'pl',
    ]);

    $revision = \App\Models\ContentRevision::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => $revisionHtml,
        'meta' => $revisionMeta,
        'is_active' => true,
    ]);

    $content->update([
        'current_version_id' => $version->id,
        'current_revision_id' => $revision->id,
    ]);

    return [$workspace, $site, $content->fresh(['workspace', 'currentVersion', 'currentRevision', 'seo', 'teamMember'])];
}
