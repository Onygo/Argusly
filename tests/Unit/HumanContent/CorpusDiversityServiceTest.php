<?php

use App\Services\HumanContent\CorpusDiversityService;

function repeatedCorpusHtml(): string
{
    return <<<'HTML'
<h1>Why approval speed changes content quality</h1>
<p>The central problem is not that teams lack ideas. It is that every useful idea loses context while it waits for approval.</p>
<h2>The signal appears in the handoff</h2>
<p>In practice, teams that review briefs within 24 hours preserve the original customer language and keep the thesis sharper.</p>
<h2>What the editor should decide</h2>
<p>The practical recommendation is to treat approval speed as a quality control metric and review where examples disappear.</p>
<p>Book a demo when the workflow is ready.</p>
HTML;
}

it('detects near duplicate structure across recent related content', function (): void {
    $service = new CorpusDiversityService();

    $result = $service->analyze(
        html: repeatedCorpusHtml(),
        title: 'Approval speed and content quality',
        comparisonDocuments: [[
            'title' => 'Approval speed checklist',
            'html' => repeatedCorpusHtml(),
            'content_id' => 'existing-content',
            'draft_id' => 'existing-draft',
        ]],
    );

    expect($result['status'])->toBe('review')
        ->and($result['penalty'])->toBeGreaterThan(0)
        ->and($result['findings'])->not->toBeEmpty()
        ->and(collect($result['findings'])->pluck('type'))->toContain('heading_similarity')
        ->and(collect($result['findings'])->pluck('type'))->toContain('structure_similarity');
});

it('allows genuinely different articles', function (): void {
    $service = new CorpusDiversityService();

    $result = $service->analyze(
        html: <<<'HTML'
<h1>How search intent changes product education</h1>
<p>A buyer does not read a comparison page with the same patience as a research guide, so the article should start with the decision they are trying to make.</p>
<h2>Map the question before choosing proof</h2>
<p>Commercial readers need criteria, implementation risks, and evidence that the product fits their operating constraint.</p>
<h2>Turn the insight into a publishing choice</h2>
<p>The next step is to decide which objection deserves proof before the first draft is written.</p>
HTML,
        title: 'Search intent and product education',
        comparisonDocuments: [[
            'title' => 'Approval speed checklist',
            'html' => repeatedCorpusHtml(),
        ]],
    );

    expect($result['score'])->toBeGreaterThanOrEqual(55)
        ->and($result['penalty'])->toBeLessThan(16);
});
