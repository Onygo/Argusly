<?php

require_once __DIR__ . '/ContentIntelligenceTestHelpers.php';

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Content\ContentHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('builds deterministic health indicators from content html and metadata', function () {
    config()->set('content_refresh.thresholds.type_word_count_targets.article', 900);

    [$workspace, $site] = makeContentIntelligenceContext('health-snapshot');
    $content = makeContentVariant($workspace, $site, 'AI Governance Guide', 'en', [
        'seo_title' => 'AI Governance Guide',
        'seo_meta_description' => null,
        'seo_h1' => 'AI governance checklist',
        'type' => 'article',
    ]);

    $revision = ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => '<h1>AI governance checklist</h1><h2>FAQ</h2><p>Updated for 2021 and 2024.</p><p><a href="/blog/internal-one">Internal one</a><a href="https://health-snapshot.example.com/blog/internal-two">Internal two</a><a href="https://external.example.com/source">External</a></p>',
        'meta' => [],
        'is_active' => true,
    ]);

    $content->forceFill([
        'current_revision_id' => $revision->id,
    ])->save();

    $snapshot = app(ContentHealthService::class)->snapshot($content);

    expect($snapshot['heading_count'])->toBe(2)
        ->and($snapshot['headings'])->toBe(['AI governance checklist', 'FAQ'])
        ->and($snapshot['internal_link_count'])->toBe(2)
        ->and($snapshot['body_years'])->toBe([2021, 2024])
        ->and($snapshot['has_faq'])->toBeTrue()
        ->and($snapshot['missing_seo_fields'])->toBe(['seo_meta_description'])
        ->and($snapshot['title_h1_mismatch'])->toBeTrue()
        ->and($snapshot['target_word_count'])->toBe(900)
        ->and($snapshot['link_urls'])->toBe([
            '/blog/internal-one',
            'https://health-snapshot.example.com/blog/internal-two',
            'https://external.example.com/source',
        ]);
});
