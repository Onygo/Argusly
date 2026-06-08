<?php

use App\Models\LlmTrackingQuery;
use App\Services\LlmTracking\LlmAuthorityEntityExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('extracts and normalizes high-performing entities from realistic answers', function () {
    $extractor = app(LlmAuthorityEntityExtractor::class);
    $query = new LlmTrackingQuery([
        'query_text' => 'best AI content tools for SEO',
        'target_brand' => 'Argusly',
        'target_domain' => 'argusly.com',
        'brand_terms' => ['Argusly'],
        'competitor_terms' => ['Semrush'],
    ]);

    $answer = 'For AI content and GEO workflows, Semrush, Ahrefs SEO, Surfer SEO, Frase, Profound, AthenaHQ, Otterly, Peec, SE Ranking, Rankability, Clearscope, Scalenut, and NeuronWriter are commonly referenced. Ahrefs.com is also cited in brand visibility discussions.';

    $entities = $extractor->extract($answer, $query, [
        ['url' => 'https://ahrefs.com/brand-radar', 'domain' => 'ahrefs.com', 'type' => 'website'],
        ['url' => 'https://www.semrush.com/features/ai-visibility', 'domain' => 'www.semrush.com', 'type' => 'website'],
    ]);

    $names = collect($entities)->pluck('normalized_name')->all();

    expect($names)->toContain('semrush')
        ->and($names)->toContain('ahrefs')
        ->and($names)->toContain('surfer')
        ->and($names)->toContain('frase')
        ->and(collect($entities)->firstWhere('normalized_name', 'ahrefs')['source_urls'])->not->toBeEmpty();
});

it('prevents generic false positives and ignores the tracked brand', function () {
    $extractor = app(LlmAuthorityEntityExtractor::class);
    $query = new LlmTrackingQuery([
        'query_text' => 'tools to improve AI search visibility',
        'target_brand' => 'Argusly',
        'brand_terms' => ['Argusly'],
    ]);

    $entities = $extractor->extract(
        'Argusly appears in this Answer with Best Content Tools and AI Search Visibility as generic headings.',
        $query,
    );

    expect(collect($entities)->pluck('normalized_name')->all())
        ->not->toContain('argusly')
        ->not->toContain('best content tools')
        ->not->toContain('ai search visibility');
});
