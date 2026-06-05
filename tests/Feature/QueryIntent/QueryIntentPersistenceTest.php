<?php

use App\DTO\QueryIntent\QueryIntentInput;
use App\Models\Organization;
use App\Models\QueryIntentClassification;
use App\Models\Workspace;
use App\Services\QueryIntent\QueryIntentIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists reusable query intent classifications idempotently', function () {
    $organization = Organization::query()->create([
        'name' => 'Query Intent Org',
        'slug' => 'query-intent-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'name' => 'Query Intent Workspace',
        'organization_id' => $organization->id,
    ]);

    $input = new QueryIntentInput(
        title: 'Implementation guide for answer blocks',
        query: 'how to implement answer blocks',
        text: 'Developers and marketers need a setup workflow to configure schema, answer blocks, and AI visibility tracking.',
        sourceType: 'brief',
        sourceKey: 'brief-123',
        workspaceId: (string) $workspace->id,
        organizationId: $organization->id,
    );

    $first = app(QueryIntentIntelligenceService::class)->classifyAndPersist($input);
    $second = app(QueryIntentIntelligenceService::class)->classifyAndPersist($input);

    expect($first->id)->toBe($second->id)
        ->and(QueryIntentClassification::query()->count())->toBe(1)
        ->and($first->primary_intent)->toBe('implementation')
        ->and($first->funnel_stage)->toBe('consideration')
        ->and($first->normalized_payload['schema'])->toBe('query_intent_intelligence.v1');
});
