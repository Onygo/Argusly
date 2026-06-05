<?php

use App\Services\Drafts\Intelligence\DraftStructureParser;

it('parses intro headings sections conclusion and cta candidate blocks from draft html', function () {
    $parser = app(DraftStructureParser::class);

    $html = <<<'HTML'
<h1>Telecom automation in 30 days</h1>
<p>Telecom automation is the practice of replacing repetitive telecom workflows with rule-based or AI-assisted steps.</p>
<h2>Summary</h2>
<p>In short, start with one workflow, define the owner, measure one pilot outcome, and document the first telecom team result.</p>
<h2>Choose the right process</h2>
<p>Map repetitive tasks and identify the best first pilot.</p>
<ul><li>Start with one team</li><li>Document current blockers</li></ul>
<h2>Next step</h2>
<p>Plan a short workshop with your operations team and start your first pilot.</p>
HTML;

    $result = $parser->parse($html);

    expect($result['intro'])->toContain('Telecom automation is the practice')
        ->and($result['conclusion'])->toContain('Plan a short workshop')
        ->and($result['headings'])->toHaveCount(4)
        ->and($result['sections'])->toHaveCount(4)
        ->and($result['cta_candidate_blocks'])->not->toBeEmpty()
        ->and($result['summary_section_count'])->toBe(1)
        ->and($result['step_section_count'])->toBeGreaterThan(0)
        ->and($result['definition_passages'])->not->toBeEmpty()
        ->and($result['extractable_passages'])->not->toBeEmpty()
        ->and($result['word_count'])->toBeGreaterThan(10);
});
