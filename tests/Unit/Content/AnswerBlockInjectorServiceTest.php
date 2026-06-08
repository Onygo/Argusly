<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentRenderArtifact;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\StructuredAnswerBlock;
use App\Models\Workspace;
use App\Services\Content\AnswerBlockInjectorService;
use App\Services\Content\AnswerBlockSchemaService;
use App\Services\Markdown\MarkdownArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('injects blocks after a matching heading in ai optimized mode', function () {
    $content = makeAnswerBlockContent('<p>Intro</p><h2>Pricing</h2><p>Details</p><h2>Support</h2><p>Help</p>');
    $content->update(['answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is pricing?',
        'answer' => 'Pricing depends on the selected plan.',
        'entities' => ['pricing'],
        'order' => 0,
    ]);

    $html = app(AnswerBlockInjectorService::class)->inject((string) $content->currentRevision?->content_html, $content->fresh('answerBlocks'));

    expect($html)->toContain('<h2>Pricing</h2><section data-answer-block="true"')
        ->and(substr_count($html, 'data-answer-block="true"'))->toBe(1);
});

it('falls back to injecting after the intro paragraph when no heading matches', function () {
    $content = makeAnswerBlockContent('<p>Intro paragraph.</p><h2>Overview</h2><p>Body</p>');
    $content->update(['answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is the direct answer?',
        'answer' => 'The direct answer appears after the intro.',
        'order' => 0,
    ]);

    $html = app(AnswerBlockInjectorService::class)->inject((string) $content->currentRevision?->content_html, $content->fresh('answerBlocks'));

    expect($html)->toContain('<p>Intro paragraph.</p><section data-answer-block="true"')
        ->and($html)->not->toContain('<h2>Overview</h2><section data-answer-block="true"');
});

it('supports bottom render mode', function () {
    $content = makeAnswerBlockContent('<p>Intro</p><h2>Body</h2><p>Details</p>');
    $content->update(['answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_BOTTOM]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'Bottom question?',
        'answer' => 'Bottom answer.',
        'order' => 0,
    ]);

    $html = app(AnswerBlockInjectorService::class)->inject((string) $content->currentRevision?->content_html, $content->fresh('answerBlocks'));

    expect($html)->toEndWith('</section>')
        ->and($html)->toContain('<p>Details</p><section data-answer-block="true"');
});

it('supports disabled render mode', function () {
    $content = makeAnswerBlockContent('<p>Intro</p>');
    $content->update(['answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_DISABLED]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'Disabled question?',
        'answer' => 'Disabled answer.',
        'order' => 0,
    ]);

    $original = (string) $content->currentRevision?->content_html;
    $html = app(AnswerBlockInjectorService::class)->inject($original, $content->fresh('answerBlocks'));

    expect($html)->toBe($original);
    expect(app(AnswerBlockSchemaService::class)->forContent($content->fresh('answerBlocks')))->toBeNull();
});

it('limits visible blocks to the configured maximum', function () {
    $content = makeAnswerBlockContent('<p>Intro</p><h2>One</h2><p>A</p><h2>Two</h2><p>B</p><h2>Three</h2><p>C</p>');
    $content->update([
        'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_INLINE,
        'answer_block_max_visible' => 2,
    ]);

    foreach (range(1, 3) as $index) {
        StructuredAnswerBlock::query()->create([
            'content_id' => $content->id,
            'question' => 'Question '.$index.'?',
            'answer' => 'Answer '.$index.'.',
            'order' => $index - 1,
        ]);
    }

    $html = app(AnswerBlockInjectorService::class)->inject((string) $content->currentRevision?->content_html, $content->fresh('answerBlocks'));

    expect(substr_count($html, 'data-answer-block="true"'))->toBe(2);
});

it('prevents duplicate question injection and generates faq schema', function () {
    $content = makeAnswerBlockContent('<p>Intro</p><h2>Body</h2><p>Details</p>');
    $content->update(['answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_INLINE]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is Argusly?',
        'answer' => 'Argusly ships structured content.',
        'order' => 0,
    ]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is Argusly?',
        'answer' => 'Duplicate should not render.',
        'order' => 1,
    ]);

    $fresh = $content->fresh('answerBlocks');
    $html = app(AnswerBlockInjectorService::class)->inject((string) $content->currentRevision?->content_html, $fresh);
    $schema = app(AnswerBlockSchemaService::class)->forContent($fresh);

    expect(substr_count($html, 'data-answer-block="true"'))->toBe(1)
        ->and(data_get($schema, 'mainEntity'))->toHaveCount(1)
        ->and(data_get($schema, 'mainEntity.0.name'))->toBe('What is Argusly?');
});

it('marks markdown artifacts stale after an answer block update', function () {
    $content = makeAnswerBlockContent('<p>Intro</p><h2>Body</h2><p>Details</p>');
    $content->update(['answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED]);

    $artifact = app(MarkdownArtifactService::class)->storeArtifact($content->fresh(['workspace', 'renderArtifacts']), [
        'markdown_locale' => 'en',
        'rendered_html' => '<p>Snapshot</p>',
        'rendered_markdown' => '# Snapshot',
        'markdown_status' => ContentRenderArtifact::STATUS_READY,
        'markdown_generated_at' => now(),
    ]);

    $block = StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What changed?',
        'answer' => 'The block changed.',
        'order' => 0,
    ]);

    $block->update(['answer' => 'The block changed again.']);

    expect($artifact->fresh()->markdown_status)->toBe(ContentRenderArtifact::STATUS_STALE);
});

function makeAnswerBlockContent(string $html): Content
{
    $organization = Organization::query()->create([
        'name' => 'Answer Block Org '.Str::random(4),
        'slug' => 'answer-block-org-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Answer Block Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en'],
    ]);

    $site = ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Answer Block Site',
        'site_url' => 'https://answers.test',
        'allowed_domains' => ['answers.test'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Answer Block Content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'draft',
        'body' => $html,
        'source' => 'pl',
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

    return $content->fresh(['workspace', 'currentVersion', 'currentRevision', 'answerBlocks']);
}
