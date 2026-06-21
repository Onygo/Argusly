<?php

use App\Services\HumanSignals\ContentHumanityAnalyzer;

it('penalizes generic AI slop language', function (): void {
    $result = app(ContentHumanityAnalyzer::class)->analyze(
        'It is important to remember that digital transformation is increasingly important. Organizations should innovate because innovation is key.'
    );

    expect($result['ai_slop_score'])->toBeGreaterThan(40)
        ->and($result['humanity_score'])->toBeLessThan(70)
        ->and($result['matched_cliches'])->toContain('it is important to');
});

it('rewards concrete observed evidence markers', function (): void {
    $result = app(ContentHumanityAnalyzer::class)->analyze(
        'Argusly detected 3 FAQ gaps on high visibility pages and measured a 42% citation increase after answer blocks were added.'
    );

    expect($result['humanity_score'])->toBeGreaterThan(80)
        ->and($result['ai_slop_score'])->toBeLessThan(20)
        ->and($result['evidence_marker_count'])->toBeGreaterThan(1);
});
