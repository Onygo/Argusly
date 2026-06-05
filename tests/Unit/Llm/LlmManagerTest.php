<?php

use App\Models\Organization;
use App\Models\LlmRoutingRule;
use App\Models\Workspace;
use App\Services\Llm\LlmManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('resolves default provider from config', function () {
    config(['llm.default_provider' => 'anthropic']);

    $manager = app(LlmManager::class);

    expect($manager->resolveProviderName())->toBe('anthropic');
});

it('prefers explicit metadata provider override', function () {
    config(['llm.default_provider' => 'openai']);

    $manager = app(LlmManager::class);

    expect($manager->resolveProviderName(metadata: ['provider' => 'gemini']))->toBe('gemini');
});

it('uses workspace routing rule provider override when present', function () {
    $organization = Organization::query()->create([
        'name' => 'LLM Org',
        'slug' => 'llm-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'LLM Workspace',
        'organization_id' => $organization->id,
    ]);

    LlmRoutingRule::query()->create([
        'scope_type' => 'workspace',
        'scope_id' => (string) $workspace->id,
        'feature' => 'draft_generation',
        'modality' => 'text',
        'inherit_global' => false,
        'provider' => 'gemini',
        'model' => 'gemini-2.0-flash',
        'fallback_enabled' => false,
        'is_enabled' => true,
    ]);

    $manager = app(LlmManager::class);

    expect($manager->resolveProviderName(metadata: [
        'workspaceId' => (string) $workspace->id,
        'feature' => 'draft_generation',
    ]))->toBe('gemini');
});
