<?php

use App\Services\HumanContent\HumanContentScoreService;

function strongHumanContentHtml(): string
{
    return <<<'HTML'
<h1>Why approval speed changes content quality</h1>
<p>The central problem is not that teams lack ideas. It is that every useful idea loses context while it waits for approval, which changes what the article can realistically prove.</p>
<h2>The signal appears in the handoff</h2>
<p>In practice, teams that review briefs within 24 hours preserve the original customer language. One publishing team we observed kept the same thesis but replaced three broad claims with campaign examples after reviewing search notes and sales objections.</p>
<p>That evidence matters because the operational constraint is visible: when review cycles stretch, writers use safer phrases and fewer precise nouns. The result is content that sounds correct but teaches less.</p>
<h2>What the editor should decide</h2>
<p>The practical recommendation is to treat approval speed as a quality control metric. Measure where examples disappear, ask which claim needs proof, and decide before drafting which counterargument deserves space.</p>
<ul><li>Keep the thesis close to the reader tension.</li><li>Add one field observation to each major claim.</li><li>Remove generic summary language when the takeaway is already clear.</li></ul>
HTML;
}

it('returns a complete human content score payload', function (): void {
    $service = new HumanContentScoreService();

    $score = $service->score(
        html: strongHumanContentHtml(),
        title: 'Approval speed and content quality',
        briefMetadata: ['key_points' => ['approval speed', 'quality control metric']],
        editorialPlan: [
            'central_thesis' => 'Approval speed changes content quality by preserving field context.',
            'unique_angle' => 'Review latency is an editorial quality signal.',
            'reader_misconception' => 'Content quality is only a writer skill problem.',
            'expected_reader_takeaway' => 'Measure approval speed as part of editorial governance.',
        ],
        brandVoice: ['tone' => 'practical and direct'],
        writerProfile: ['summary' => 'Practical consultant with editorial judgment'],
        researchSummary: ['insights' => ['review cycles preserve customer language', 'approval speed affects examples']],
    );

    expect($score)->toHaveKeys([
        'human_content_score',
        'editorial_quality_score',
        'originality_score',
        'narrative_flow_score',
        'human_voice_score',
        'expertise_score',
        'insight_density_score',
        'evidence_usage_score',
        'rhythm_score',
        'curiosity_score',
        'ai_fingerprint_score',
        'uniqueness_score',
        'dimension_breakdown',
        'findings',
        'recommendations',
        'status',
        'passed',
        'suggested_humanization_actions',
        'ai_fingerprint',
    ]);

    expect($score['human_content_score'])->toBeInt()
        ->and($score['dimension_breakdown'])->toHaveKeys(['editorial_quality_score', 'ai_fingerprint_score'])
        ->and($score['ai_fingerprint'])->toHaveKeys(['score', 'findings', 'humanization_actions'])
        ->and($score['findings'])->not->toBeEmpty()
        ->and($score['recommendations'])->not->toBeEmpty();
});

it('scores weak generic content lower than editorial content', function (): void {
    $service = new HumanContentScoreService();

    $weak = $service->score(
        html: '<h1>Introduction</h1><p>In today\'s digital landscape, content is a game changer. It is important to note that businesses need robust solutions.</p><h2>Main Section</h2><p>Overall, teams should unlock the power of better workflows. This article will delve into the topic.</p><h2>Conclusion</h2><p>In conclusion, content is important for success.</p>',
        title: 'Content workflows',
        editorialPlan: ['central_thesis' => 'Approval speed changes content quality.'],
    );

    $strong = $service->score(
        html: strongHumanContentHtml(),
        title: 'Approval speed and content quality',
        briefMetadata: ['key_points' => ['approval speed', 'quality control metric']],
        editorialPlan: [
            'central_thesis' => 'Approval speed changes content quality by preserving field context.',
            'unique_angle' => 'Review latency is an editorial quality signal.',
            'expected_reader_takeaway' => 'Measure approval speed as part of editorial governance.',
        ],
        researchSummary: ['insights' => ['review cycles preserve customer language', 'approval speed affects examples']],
    );

    expect($weak['human_content_score'])->toBeLessThan($strong['human_content_score'])
        ->and($weak['ai_fingerprint_score'])->toBeGreaterThan($strong['ai_fingerprint_score'])
        ->and($weak['evidence_usage_score'])->toBeLessThan($strong['evidence_usage_score'])
        ->and($weak['passed'])->toBeFalse();
});

it('rewards thesis examples evidence and rhythm variation', function (): void {
    $service = new HumanContentScoreService();

    $plain = $service->score(
        html: '<h1>Approval workflow</h1><p>Approval workflows help teams improve quality. Approval workflows should be clear. Approval workflows are useful for companies.</p>',
        title: 'Approval workflow',
    );

    $strong = $service->score(
        html: strongHumanContentHtml(),
        title: 'Approval speed and content quality',
        editorialPlan: [
            'central_thesis' => 'Approval speed changes content quality by preserving field context.',
            'unique_angle' => 'Review latency is an editorial quality signal.',
        ],
        researchSummary: ['insights' => ['review cycles preserve customer language']],
    );

    expect($strong['editorial_quality_score'])->toBeGreaterThan($plain['editorial_quality_score'])
        ->and($strong['narrative_flow_score'])->toBeGreaterThan($plain['narrative_flow_score'])
        ->and($strong['human_voice_score'])->toBeGreaterThan($plain['human_voice_score']);
});

it('uses corpus diversity to reduce uniqueness score for repeated structures', function (): void {
    $service = new HumanContentScoreService();

    $withoutOverlap = $service->score(
        html: strongHumanContentHtml(),
        title: 'Approval speed and content quality',
        editorialPlan: [
            'central_thesis' => 'Approval speed changes content quality by preserving field context.',
            'unique_angle' => 'Review latency is an editorial quality signal.',
        ],
        recentRelatedContent: [[
            'title' => 'Search intent and product education',
            'html' => '<h1>How search intent changes product education</h1><p>A buyer reads commercial content to make a decision.</p><h2>Map the objection</h2><p>The article needs evidence, risk, and a clear next step.</p>',
        ]],
    );

    $withOverlap = $service->score(
        html: strongHumanContentHtml(),
        title: 'Approval speed and content quality',
        editorialPlan: [
            'central_thesis' => 'Approval speed changes content quality by preserving field context.',
            'unique_angle' => 'Review latency is an editorial quality signal.',
        ],
        recentRelatedContent: [[
            'title' => 'Approval speed checklist',
            'html' => strongHumanContentHtml(),
        ]],
    );

    expect($withOverlap['uniqueness_score'])->toBeLessThan($withoutOverlap['uniqueness_score'])
        ->and(data_get($withOverlap, 'corpus_diversity.findings'))->not->toBeEmpty()
        ->and(data_get($withOverlap, 'signals.corpus_diversity_risk_score'))->toBeGreaterThan(0);
});
