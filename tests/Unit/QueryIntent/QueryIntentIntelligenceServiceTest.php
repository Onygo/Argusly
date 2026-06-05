<?php

use App\DTO\QueryIntent\QueryIntentInput;
use App\Services\QueryIntent\QueryIntentIntelligenceService;

it('classifies comparison decision intent for marketers', function () {
    $result = app(QueryIntentIntelligenceService::class)->classify(new QueryIntentInput(
        title: 'Best PublishLayer alternatives for AI SEO teams',
        query: 'publishlayer vs competitor pricing',
        text: 'Compare AI SEO platforms, pricing, demo readiness, content workflows, and campaign planning for marketing teams.',
        sourceType: 'test',
    ));

    expect($result->primaryIntent)->toBe('comparison')
        ->and($result->funnelStage)->toBe('decision')
        ->and($result->buyerRole)->toBe('marketers')
        ->and($result->businessImpact)->toBeIn(['high', 'strategic'])
        ->and($result->priorityScore)->toBeGreaterThan(70)
        ->and($result->aiEnrichment['status'])->toBe('ready_for_ai');
});

it('classifies migration and risk evaluation signals for enterprise buyers', function () {
    $result = app(QueryIntentIntelligenceService::class)->classify(new QueryIntentInput(
        title: 'Migrate from legacy content ops safely',
        query: 'content platform migration compliance risks',
        text: 'Enterprise buyers need a migration plan with governance, security, compliance, SLA reliability, audit history, and risk evaluation before switching.',
        sourceType: 'test',
    ));

    expect($result->primaryIntent)->toBe('migration')
        ->and($result->secondaryIntents)->toContain('risk_evaluation')
        ->and($result->funnelStage)->toBe('decision')
        ->and($result->buyerRole)->toBe('enterprise_buyers')
        ->and($result->urgency)->toBeIn(['high', 'critical']);
});
