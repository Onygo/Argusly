<?php

use App\Services\HumanSignals\HumanSignalQualityService;

it('scores specific evidenced human signals higher than generic observations', function (): void {
    $service = app(HumanSignalQualityService::class);

    $specific = $service->scoreCandidate([
        'title' => 'Telecom FAQ pages gained 3x more AI citations',
        'observation' => 'LLM tracking detected a 3x citation lift for telecom FAQ pages across 8 recent runs.',
        'impact' => 'Prioritize FAQ expansion for the pages that already show AI source traction.',
        'confidence_score' => 86,
        'evidence' => [['source_type' => 'llm_tracking_query_runs']],
    ]);

    $generic = $service->scoreCandidate([
        'title' => 'Content is important',
        'observation' => 'Organizations should improve content because digital transformation is increasingly important.',
        'impact' => '',
        'confidence_score' => 50,
        'evidence' => [],
    ]);

    expect($specific['human_signal_score'])->toBeGreaterThan($generic['human_signal_score'])
        ->and($specific['evidence_score'])->toBeGreaterThan($generic['evidence_score'])
        ->and($specific['specificity_score'])->toBeGreaterThan($generic['specificity_score']);
});
