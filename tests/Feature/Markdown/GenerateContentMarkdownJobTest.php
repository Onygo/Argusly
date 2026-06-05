<?php

use App\Jobs\GenerateContentMarkdownJob;
use App\Models\ContentRenderArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('generates a ready markdown artifact through the generation job', function () {
    [, , $content] = makeMarkdownCommandContent();

    $job = new GenerateContentMarkdownJob($content->id);
    $job->handle(app(\App\Services\Markdown\MarkdownArtifactService::class));

    $artifact = ContentRenderArtifact::query()
        ->where('content_id', $content->id)
        ->where('markdown_locale', 'en')
        ->first();

    expect($artifact)->not->toBeNull()
        ->and($artifact?->markdown_status)->toBe(ContentRenderArtifact::STATUS_READY)
        ->and($artifact?->rendered_markdown)->toContain('# Markdown Command Content')
        ->and($artifact?->rendered_markdown)->toContain('Command body');
});

it('queues markdown regeneration when the active content version changes', function () {
    Queue::fake();

    [, , $content] = makeMarkdownCommandContent();

    $content->currentVersion->update([
        'body' => '<p>Updated command body</p>',
    ]);

    Queue::assertPushed(GenerateContentMarkdownJob::class, function (GenerateContentMarkdownJob $job) use ($content) {
        return $job->contentId === (string) $content->id;
    });
});
