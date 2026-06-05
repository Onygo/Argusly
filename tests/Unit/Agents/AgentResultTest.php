<?php

use App\Agents\Data\AgentResult;
use App\Agents\Support\AgentRunStatus;
use App\Agents\Support\AgentTriggerType;
use Carbon\CarbonImmutable;

it('stores normalized payloads correctly', function () {
    $startedAt = CarbonImmutable::parse('2026-04-09 09:00:00');
    $finishedAt = CarbonImmutable::parse('2026-04-09 09:00:05');

    $result = AgentResult::warning(
        agentKey: 'draft.recommendations',
        summary: 'Suggestions available.',
        suggestions: [
            ['type' => 'refresh', 'trigger' => AgentTriggerType::DEBUG],
        ],
        actions: [
            ['action' => 'queue_refresh', 'scheduled_for' => $finishedAt],
        ],
        warnings: ['Needs editorial review'],
        metrics: [
            'score' => 82,
            'status' => AgentRunStatus::WARNING,
        ],
        rawPayload: [
            'generated_at' => $startedAt,
            'trigger_type' => AgentTriggerType::MANUAL,
        ],
        startedAt: $startedAt,
        finishedAt: $finishedAt,
    );

    expect($result->toArray())->toMatchArray([
        'agent_key' => 'draft.recommendations',
        'status' => 'warning',
        'summary' => 'Suggestions available.',
        'suggestions' => [
            ['type' => 'refresh', 'trigger' => 'debug'],
        ],
        'actions' => [
            ['action' => 'queue_refresh', 'scheduled_for' => $finishedAt->toAtomString()],
        ],
        'warnings' => ['Needs editorial review'],
        'metrics' => [
            'score' => 82,
            'status' => 'warning',
        ],
        'raw_payload' => [
            'generated_at' => $startedAt->toAtomString(),
            'trigger_type' => 'manual',
        ],
        'started_at' => $startedAt->toAtomString(),
        'finished_at' => $finishedAt->toAtomString(),
    ]);
});
