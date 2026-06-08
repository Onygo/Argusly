<?php

use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\StructuredAnswerBlock;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Aeo\AeoScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('scores structured answer-first content with a usable breakdown', function () {
    $organization = Organization::query()->create([
        'name' => 'AEO Org',
        'slug' => 'aeo-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'AEO Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en'],
    ]);

    $site = ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'AEO Site',
        'site_url' => 'https://aeo.example.test',
        'base_url' => 'https://aeo.example.test',
        'allowed_domains' => ['aeo.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'AEO score explained',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'publish_status' => 'published',
        'primary_keyword' => 'AEO score',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'draft',
        'body' => '<p>AEO score is a visibility signal for answer-first content.</p><h2>Why it matters</h2><ul><li>AI visibility</li></ul><h2>FAQ</h2><p>Helpful answers.</p>',
        'source' => 'pl',
    ]);

    $revision = ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => '<p>AEO score is a visibility signal for answer-first content. Argusly helps teams improve ChatGPT and Google AI readability.</p><h2>Why it matters</h2><ul><li>AI visibility</li></ul><h2>FAQ</h2><p>Helpful answers.</p>',
        'is_active' => true,
    ]);

    $content->update([
        'current_version_id' => $version->id,
        'current_revision_id' => $revision->id,
    ]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is an AEO score?',
        'answer' => 'An AEO score is a content quality score for direct AI answer visibility.',
        'entities' => ['Argusly', 'ChatGPT', 'Google AI'],
        'order' => 0,
    ]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'Why does AEO matter?',
        'answer' => 'AEO matters because structured answers make retrieval easier for AI systems.',
        'entities' => ['AI systems'],
        'order' => 1,
    ]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'How can teams improve AEO?',
        'answer' => 'Teams improve AEO by adding direct answers, headings, entities, and FAQs.',
        'entities' => ['FAQs'],
        'order' => 2,
    ]);

    $result = app(AeoScoreService::class)->score($content->fresh(['currentRevision', 'currentVersion', 'answerBlocks']));

    expect($result['score'])->toBeGreaterThan(70)
        ->and(data_get($result, 'breakdown.answer_clarity'))->toBeGreaterThan(10)
        ->and(data_get($result, 'breakdown.structure'))->toBeGreaterThan(10)
        ->and($result['improvements'])->toBeArray();
});
