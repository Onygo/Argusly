<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDraftComparisonStatusContext(string $prefix = 'draft-compare-status'): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Compare Status Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Status Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Status Site',
        'site_url' => 'https://status-flow.example.com',
        'allowed_domains' => ['status-flow.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Status brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'mode' => 'compare_multiple',
        'status' => DraftComparison::STATUS_PENDING,
    ]);

    return [$comparison, $brief, $site, $workspace, $organization];
}

it('recalculates comparison status using variant aggregate rules', function () {
    [$comparison] = makeDraftComparisonStatusContext();

    expect($comparison->recalculateAggregateStatus())->toBe(DraftComparison::STATUS_PENDING);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_QUEUED,
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'status' => DraftComparisonVariant::STATUS_PENDING,
    ]);

    expect($comparison->fresh()->recalculateAggregateStatus())->toBe(DraftComparison::STATUS_QUEUED);

    DraftComparisonVariant::query()
        ->where('draft_comparison_id', $comparison->id)
        ->where('provider_key', 'openai')
        ->update(['status' => DraftComparisonVariant::STATUS_PROCESSING]);

    expect($comparison->fresh()->recalculateAggregateStatus())->toBe(DraftComparison::STATUS_PROCESSING);

    DraftComparisonVariant::query()
        ->where('draft_comparison_id', $comparison->id)
        ->where('provider_key', 'openai')
        ->update(['status' => DraftComparisonVariant::STATUS_COMPLETED]);

    DraftComparisonVariant::query()
        ->where('draft_comparison_id', $comparison->id)
        ->where('provider_key', 'anthropic')
        ->update(['status' => DraftComparisonVariant::STATUS_FAILED]);

    expect($comparison->fresh()->recalculateAggregateStatus())->toBe(DraftComparison::STATUS_PARTIALLY_FAILED);

    DraftComparisonVariant::query()
        ->where('draft_comparison_id', $comparison->id)
        ->update(['status' => DraftComparisonVariant::STATUS_FAILED]);

    $status = $comparison->fresh()->recalculateAggregateStatus();
    $comparison->refresh();

    expect($status)->toBe(DraftComparison::STATUS_FAILED)
        ->and($comparison->failed_at)->not->toBeNull();
});

it('sets started_at and completed_at when all variants complete', function () {
    [$comparison] = makeDraftComparisonStatusContext('draft-compare-status-completed');

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
    ]);

    $status = $comparison->fresh()->recalculateAggregateStatus();
    $comparison->refresh();

    expect($status)->toBe(DraftComparison::STATUS_COMPLETED)
        ->and($comparison->started_at)->not->toBeNull()
        ->and($comparison->completed_at)->not->toBeNull()
        ->and($comparison->failed_at)->toBeNull();
});

it('keeps cancelled status when explicitly cancelled', function () {
    [$comparison] = makeDraftComparisonStatusContext('draft-compare-status-cancelled');

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
    ]);

    $comparison->markCancelled();

    expect($comparison->recalculateAggregateStatus())->toBe(DraftComparison::STATUS_CANCELLED)
        ->and((string) $comparison->fresh()->status)->toBe(DraftComparison::STATUS_CANCELLED);
});

it('variant transition helpers update parent aggregate status', function () {
    [$comparison] = makeDraftComparisonStatusContext('draft-compare-status-variant');

    $variant = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_PENDING,
    ]);

    $variant->markQueued();
    expect((string) $comparison->fresh()->status)->toBe(DraftComparison::STATUS_QUEUED);

    $variant->markProcessing();
    $variant->refresh();
    expect($variant->started_at)->not->toBeNull();
    expect((string) $comparison->fresh()->status)->toBe(DraftComparison::STATUS_PROCESSING);

    $variant->markCompleted();
    $variant->refresh();
    $comparison->refresh();

    expect($variant->completed_at)->not->toBeNull();
    expect((string) $comparison->status)->toBe(DraftComparison::STATUS_COMPLETED);
});
