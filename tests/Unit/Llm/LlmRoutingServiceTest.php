<?php

use App\Models\LlmRoutingRule;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Llm\LlmRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('resolves global defaults when no rules exist', function () {
    config([
        'llm.default_provider' => 'openai',
        'llm.providers.openai.default_model' => 'gpt-4.1-mini',
        'argusly.ai.images.provider' => 'openai',
        'argusly.ai.images.openai.model' => 'gpt-image-1',
    ]);

    $routing = app(LlmRoutingService::class);

    $route = $routing->resolve('draft_generation', 'text');
    expect($route['provider'])->toBe('openai')
        ->and($route['model'])->toBe('gpt-4.1-mini');
});

it('applies workspace rule over global rule', function () {
    $organization = Organization::query()->create([
        'name' => 'Routing Org',
        'slug' => 'routing-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Routing Workspace',
        'organization_id' => $organization->id,
    ]);

    LlmRoutingRule::query()->create([
        'scope_type' => 'global',
        'scope_id' => null,
        'feature' => 'draft_generation',
        'modality' => 'text',
        'inherit_global' => false,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'fallback_enabled' => false,
        'is_enabled' => true,
    ]);

    LlmRoutingRule::query()->create([
        'scope_type' => 'workspace',
        'scope_id' => (string) $workspace->id,
        'feature' => 'draft_generation',
        'modality' => 'text',
        'inherit_global' => false,
        'provider' => 'anthropic',
        'model' => 'claude-3-5-sonnet-latest',
        'fallback_enabled' => false,
        'is_enabled' => true,
    ]);

    $routing = app(LlmRoutingService::class);

    $route = $routing->resolve('draft_generation', 'text', (string) $workspace->id);
    expect($route['provider'])->toBe('anthropic')
        ->and($route['model'])->toBe('claude-3-5-sonnet-latest');
});
