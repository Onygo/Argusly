<?php

use App\Models\Brief;
use App\Services\HumanContent\HumanizationService;

it('runs only below human content thresholds', function (): void {
    $service = app(HumanizationService::class);

    expect($service->shouldHumanize([
        'passed' => true,
        'human_content_score' => 82,
        'editorial_quality_score' => 78,
        'ai_fingerprint_score' => 22,
    ]))->toBeFalse()
        ->and($service->shouldHumanize([
            'passed' => false,
            'human_content_score' => 58,
            'editorial_quality_score' => 54,
            'ai_fingerprint_score' => 68,
        ]))->toBeTrue();
});

it('preserves facts internal links and schema while applying targeted edits', function (): void {
    $brief = new Brief([
        'title' => 'Approval speed and content quality',
        'primary_keyword' => 'approval speed',
    ]);

    $html = <<<'HTML'
<script type="application/ld+json">{"@type":"Article","name":"Approval speed"}</script>
<h1>Introduction</h1>
<p>In today's digital landscape, Argusly teams reviewed 42 briefs and it is important to note that approval speed is a game changer.</p>
<p>Read the <a href="/en/blog/editorial-workflows">editorial workflow guide</a> for the original evidence.</p>
<h2>Main Section</h2>
<p>Overall, approval speed should consider the handoff between Sales Teams and Content Teams.</p>
<h2>Conclusion</h2>
<p>In conclusion, book a demo when the workflow is ready.</p>
HTML;

    $result = app(HumanizationService::class)->humanize(
        html: $html,
        humanFindings: ['The draft contains AI-like generic phrasing.'],
        aiFingerprintFindings: [
            ['type' => 'generic_headings'],
            ['type' => 'chatgpt_vocabulary'],
            ['type' => 'marketing_cliches'],
            ['type' => 'predictable_openings'],
            ['type' => 'predictable_endings'],
            ['type' => 'recommendation_overuse'],
        ],
        editorialPlan: ['central_thesis' => 'Approval speed changes content quality by preserving field context.'],
        brief: $brief,
    );

    expect($result['changed'])->toBeTrue()
        ->and($result['improved_html'])->toContain('/en/blog/editorial-workflows')
        ->and($result['improved_html'])->toContain('42')
        ->and($result['improved_html'])->toContain('Argusly')
        ->and($result['improved_html'])->toContain('Sales Teams')
        ->and($result['improved_html'])->toContain('Content Teams')
        ->and($result['improved_html'])->toContain('application/ld+json')
        ->and($result['preserved_validation']['links_preserved'])->toBeTrue()
        ->and($result['preserved_validation']['schema_preserved'])->toBeTrue()
        ->and($result['preserved_validation']['facts_preserved'])->toBeTrue()
        ->and($result['improved_html'])->not->toContain('<h1>Introduction</h1>')
        ->and($result['improved_html'])->not->toContain('In today\'s digital landscape');
});

it('uses corpus diversity findings as humanization guidance', function (): void {
    $brief = new Brief([
        'title' => 'Approval speed and content quality',
        'primary_keyword' => 'approval speed',
    ]);

    $html = <<<'HTML'
<h1>Introduction</h1>
<p>Approval speed should consider the handoff between teams because the workflow is useful when the review cycle is short.</p>
<h2>Main Section</h2>
<p>This depends on the operating context and the decision should consider how teams approve drafts before publication.</p>
<h2>Conclusion</h2>
<p>Book a demo when the workflow is ready.</p>
HTML;

    $result = app(HumanizationService::class)->humanize(
        html: $html,
        editorialPlan: ['central_thesis' => 'Approval speed changes content quality by preserving field context.'],
        brief: $brief,
        corpusDiversityFindings: [
            [
                'type' => 'heading_similarity',
                'recommendation' => 'Replace recurring headings with specific editorial claims.',
                'humanization_action' => 'Reshape headings and section movement to avoid corpus repetition.',
            ],
            [
                'type' => 'argument_similarity',
                'recommendation' => 'Reorder the argument and add a distinct decision criterion.',
            ],
        ],
    );

    expect($result['changed'])->toBeTrue()
        ->and($result['improved_html'])->not->toContain('<h1>Introduction</h1>')
        ->and($result['before_after_notes'])->toContain('Reshape headings and section movement to avoid corpus repetition.')
        ->and(data_get($result, 'context.corpus_diversity_finding_count'))->toBe(2);
});
