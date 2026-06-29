<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionApproval;
use App\Models\AgenticMarketingExecutionAsset;
use App\Models\AgenticMarketingExecutionAuditLog;
use App\Models\AgenticMarketingExecutionFeedback;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityExecutionContinuityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3iContext(string $slug = 'phase-3i'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3I '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3I Workspace',
        'display_name' => 'Phase 3I Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3I Site',
        'site_url' => 'https://phase-3i.test',
        'base_url' => 'https://phase-3i.test',
        'allowed_domains' => ['phase-3i.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3I objective',
        'goal' => 'Inspect execution continuity',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3iOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3I execution continuity',
        'type' => 'content_network',
        'priority_score' => 84,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Execution continuity',
            'reasoning' => 'Execution rows need canonical reference diagnostics.',
            'angle' => 'Explain continuity before migration.',
            'target_audience' => 'content teams',
            'primary_search_intent' => 'implementation',
            'suggested_cta' => 'Review execution continuity',
            'suggested_schema' => 'Article',
            'signals' => ['cluster_id' => 'phase-3i'],
        ],
    ], $overrides));
}

function phase3iCanonical(AgenticMarketingOpportunity $legacy, array $overrides = []): Opportunity
{
    $legacy->loadMissing('objective');

    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->objective->organization_id,
        'workspace_id' => $legacy->objective->workspace_id,
        'client_site_id' => $legacy->objective->client_site_id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical Phase 3I execution continuity',
        'topic' => 'Execution continuity',
        'summary' => 'Canonical execution continuity context.',
        'priority_score' => 90,
        'recommended_actions' => [['title' => 'Keep execution legacy-parented']],
        'evidence' => [['type' => 'canonical_bridge']],
        'source_signal_summary' => ['detector_key' => 'content_network_gaps'],
        'metadata' => ['agentic_type' => (string) $legacy->type],
        'dedupe_hash' => (string) $legacy->dedupe_hash,
    ], $overrides));
}

function phase3iExecutionRows(AgenticMarketingOpportunity $opportunity): array
{
    $opportunity->loadMissing('objective');

    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $opportunity->objective_id,
        'opportunity_id' => (string) $opportunity->id,
        'action_type' => 'create_article',
        'status' => AgenticMarketingAction::STATUS_APPROVED,
        'payload' => [
            'workspace_id' => (string) $opportunity->objective->workspace_id,
            'client_site_id' => (string) $opportunity->objective->client_site_id,
            'title' => 'Execution continuity',
        ],
    ]);

    AgenticActionRun::query()->create([
        'workspace_id' => (string) $opportunity->objective->workspace_id,
        'goal_id' => (string) $opportunity->objective_id,
        'opportunity_id' => (string) $opportunity->id,
        'action_id' => (string) $action->id,
        'action_type' => 'create_article',
        'status' => AgenticActionRun::STATUS_COMPLETED,
        'input_snapshot' => ['opportunity_id' => (string) $opportunity->id],
    ]);

    $pipeline = AgenticMarketingExecutionPipeline::query()->create([
        'organization_id' => $opportunity->objective->organization_id,
        'objective_id' => (string) $opportunity->objective_id,
        'opportunity_id' => (string) $opportunity->id,
        'mode' => 'manual',
        'status' => 'awaiting_approval',
        'current_stage' => 'approval',
        'approval_status' => 'pending',
        'publishing_readiness' => 'needs_review',
        'rollback_snapshot' => [
            'opportunity' => ['id' => (string) $opportunity->id],
            'content' => ['id' => (string) Str::uuid()],
        ],
    ]);

    $briefAsset = AgenticMarketingExecutionAsset::query()->create([
        'pipeline_id' => (string) $pipeline->id,
        'objective_id' => (string) $opportunity->objective_id,
        'opportunity_id' => (string) $opportunity->id,
        'type' => 'content_brief',
        'status' => 'generated',
        'title' => 'Content brief',
        'payload' => ['title' => $opportunity->title],
        'assetable_type' => 'App\\Models\\Brief',
        'assetable_id' => (string) Str::uuid(),
        'requires_approval' => true,
    ]);

    AgenticMarketingExecutionAsset::query()->create([
        'pipeline_id' => (string) $pipeline->id,
        'objective_id' => (string) $opportunity->objective_id,
        'opportunity_id' => (string) $opportunity->id,
        'type' => 'metadata',
        'status' => 'approved',
        'title' => 'SEO metadata',
        'payload' => ['seo_title' => 'Execution continuity'],
        'requires_approval' => true,
    ]);

    AgenticMarketingExecutionApproval::query()->create([
        'pipeline_id' => (string) $pipeline->id,
        'asset_id' => (string) $briefAsset->id,
        'status' => 'pending',
        'approval_type' => 'editorial_review',
        'requested_role' => 'editor',
    ]);

    AgenticMarketingExecutionFeedback::query()->create([
        'pipeline_id' => (string) $pipeline->id,
        'asset_id' => (string) $briefAsset->id,
        'type' => 'review_note',
        'body' => 'Keep parent continuity visible.',
    ]);

    AgenticMarketingExecutionAuditLog::query()->create([
        'pipeline_id' => (string) $pipeline->id,
        'asset_id' => (string) $briefAsset->id,
        'event' => 'asset.generated',
        'after' => ['opportunity_id' => (string) $opportunity->id],
    ]);

    return [$action, $pipeline, $briefAsset];
}

it('reports a legacy-only opportunity without mutating execution state', function (): void {
    [, , , $objective] = phase3iContext('phase-3i-legacy-only');
    $legacy = phase3iOpportunity($objective);
    $updatedAt = $legacy->updated_at?->toIso8601String();

    $report = app(AgenticOpportunityExecutionContinuityService::class)->inspect($legacy);

    expect($report['legacy_agentic_opportunity_id'])->toBe($legacy->id)
        ->and($report['canonical_opportunity_id'])->toBeNull()
        ->and($report['blocked_reasons'])->toContain('missing_safe_canonical_bridge')
        ->and($report['counts']['actions'])->toBe(0)
        ->and($report['legacy_only_execution_dependencies'])->toContain('execution_routes.use_agentic_marketing_opportunities_id')
        ->and($legacy->refresh()->updated_at?->toIso8601String())->toBe($updatedAt);
});

it('reports linked canonical execution continuity and grouped execution counts', function (): void {
    [, , , $objective] = phase3iContext('phase-3i-linked');
    $legacy = phase3iOpportunity($objective);
    $canonical = phase3iCanonical($legacy);
    [, $pipeline] = phase3iExecutionRows($legacy);

    $report = app(AgenticOpportunityExecutionContinuityService::class)->inspect($legacy);

    expect($report['canonical_opportunity_id'])->toBe($canonical->id)
        ->and($report['actions_count_by_status'])->toHaveKey(AgenticMarketingAction::STATUS_APPROVED)
        ->and($report['action_runs_count_by_status'])->toHaveKey(AgenticActionRun::STATUS_COMPLETED)
        ->and($report['execution_pipelines_count_by_status'])->toHaveKey('awaiting_approval')
        ->and($report['execution_assets_count_by_type_status'])->toContain([
            'type' => 'content_brief',
            'status' => 'generated',
            'count' => 1,
        ])
        ->and($report['execution_assets_count_by_type_status'])->toContain([
            'type' => 'metadata',
            'status' => 'approved',
            'count' => 1,
        ])
        ->and($report['approvals_count_by_status'])->toHaveKey('pending')
        ->and($report['feedback_count'])->toBe(1)
        ->and($report['execution_audit_log_count'])->toBe(1)
        ->and($report['generated_references']['briefs'][0]['legacy_agentic_opportunity_id'])->toBe($legacy->id)
        ->and($report['generated_references']['rollback_snapshots'][0]['pipeline_id'])->toBe($pipeline->id)
        ->and($report['blocked_reasons'])->toContain('canonical_parent_only_lookup_would_miss_actions')
        ->and($report['blocked_reasons'])->toContain('historical_rollback_snapshots_reference_legacy_agentic_opportunity_id');
});

it('reports missing execution payload fields separately from canonical field availability', function (): void {
    $organization = Organization::query()->create([
        'name' => 'Phase 3I Missing '.Str::random(6),
        'slug' => 'phase-3i-missing-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3I Missing Workspace',
    ]);
    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'name' => 'Missing execution context',
        'goal' => 'Expose payload gaps',
        'locale' => 'en',
        'status' => 'active',
    ]);
    $legacy = phase3iOpportunity($objective, [
        'payload' => [
            'detector' => 'content_network_gaps',
            'topic' => 'Payload gaps',
            'reasoning' => null,
            'reason' => null,
            'why_this_matters' => null,
            'score_explanation' => ['summary' => null],
        ],
    ]);

    $report = app(AgenticOpportunityExecutionContinuityService::class)->inspect($legacy);

    expect($report['missing_execution_payload_fields'])->toContain('client_site_id')
        ->and($report['missing_execution_payload_fields'])->toContain('summary_or_reasoning')
        ->and($report['blocked_reasons'])->toContain('missing_execution_payload_field:client_site_id')
        ->and($report['missing_canonical_fields'])->toContain('id');
});

it('blocks continuity when duplicate canonical bridges point at one legacy row', function (): void {
    [, , , $objective] = phase3iContext('phase-3i-duplicate');
    $legacy = phase3iOpportunity($objective);
    phase3iCanonical($legacy, ['title' => 'First canonical bridge', 'dedupe_hash' => 'phase-3i-first-'.Str::random(12)]);
    phase3iCanonical($legacy, ['title' => 'Second canonical bridge', 'dedupe_hash' => 'phase-3i-second-'.Str::random(12)]);

    $report = app(AgenticOpportunityExecutionContinuityService::class)->inspect($legacy);

    expect($report['canonical_opportunity_id'])->toBeNull()
        ->and($report['blocked_reasons'])->toContain('multiple_canonical_opportunities_linked_to_agentic_row')
        ->and($report['blocked'])->toBeTrue();
});

it('reports the diagnostics command without mutating execution rows or parent ids', function (): void {
    [, $workspace, , $objective] = phase3iContext('phase-3i-command');
    $legacy = phase3iOpportunity($objective);
    phase3iCanonical($legacy);
    [$action, $pipeline, $asset] = phase3iExecutionRows($legacy);

    $legacyUpdatedAt = $legacy->updated_at?->toIso8601String();
    $actionUpdatedAt = $action->updated_at?->toIso8601String();
    $pipelineUpdatedAt = $pipeline->updated_at?->toIso8601String();
    $assetUpdatedAt = $asset->updated_at?->toIso8601String();

    $this->artisan('mos:inspect-agentic-execution-continuity', [
        '--workspace' => (string) $workspace->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Agentic execution continuity diagnostics.')
        ->expectsOutputToContain('inspected opportunities count: 1')
        ->expectsOutputToContain('linked canonical count: 1')
        ->expectsOutputToContain('actions count: 1')
        ->expectsOutputToContain('execution pipelines count: 1')
        ->expectsOutputToContain('execution assets count: 2')
        ->expectsOutputToContain('blocked reasons:')
        ->expectsOutputToContain('route/parent dependency samples:');

    expect($legacy->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and($action->refresh()->updated_at?->toIso8601String())->toBe($actionUpdatedAt)
        ->and($pipeline->refresh()->updated_at?->toIso8601String())->toBe($pipelineUpdatedAt)
        ->and($asset->refresh()->updated_at?->toIso8601String())->toBe($assetUpdatedAt)
        ->and($action->opportunity_id)->toBe($legacy->id)
        ->and($pipeline->opportunity_id)->toBe($legacy->id)
        ->and($asset->opportunity_id)->toBe($legacy->id);
});
