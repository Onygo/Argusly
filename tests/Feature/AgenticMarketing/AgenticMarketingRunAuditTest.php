<?php

use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingAuditLog;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\AgenticMarketingRunItem;
use App\Models\Content;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use App\Services\AgenticMarketing\AgenticMarketingOpportunityDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeRunAuditTenant(string $slug = 'am-run-audit'): array
{
    $org = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => $slug . ' workspace',
        'organization_id' => $org->id,
    ]);

    $user = User::factory()->create([
        'organization_id' => $org->id,
        'role' => 'admin',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$org, $workspace, $user];
}

function makeRunAuditObjective(Organization $org, Workspace $workspace): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create([
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
        'name' => 'Run audit objective',
        'goal' => 'Trace every recommendation and execution.',
        'locale' => 'en',
        'languages' => ['en'],
        'kpi_type' => 'ai_visibility',
        'approval_mode' => 'manual',
        'status' => 'active',
    ]);
}

function makeRunAuditContent(Workspace $workspace): Content
{
    return Content::query()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Traceable AM content',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'language' => 'en',
        'freshness_score' => 20,
        'lifecycle_stage' => 'refresh_needed',
    ]);
}

it('records detection and planning run lifecycle items and audit events', function () {
    [$org, $workspace] = makeRunAuditTenant();
    $objective = makeRunAuditObjective($org, $workspace);
    makeRunAuditContent($workspace);

    app(AgenticMarketingOpportunityDetectionService::class)->detect($objective->id);

    $detectionRun = AgenticMarketingRun::query()
        ->where('objective_id', $objective->id)
        ->where('payload->type', 'opportunity_detection')
        ->firstOrFail();

    expect($detectionRun->status)->toBe(AgenticMarketingRun::STATUS_COMPLETED)
        ->and($detectionRun->items()->where('type', AgenticMarketingRunItem::TYPE_DETECTION)->where('status', AgenticMarketingRunItem::STATUS_COMPLETED)->exists())->toBeTrue()
        ->and(AgenticMarketingOpportunity::query()->where('objective_id', $objective->id)->exists())->toBeTrue()
        ->and(AgenticMarketingAuditLog::query()->where('event', 'opportunity.created')->exists())->toBeTrue()
        ->and(AgenticMarketingAuditLog::query()->where('event', 'run.completed')->where('run_id', $detectionRun->id)->exists())->toBeTrue();

    $opportunity = AgenticMarketingOpportunity::query()->where('objective_id', $objective->id)->firstOrFail();
    app(AgenticMarketingActionPlanner::class)->planForOpportunity($opportunity);

    $planningRun = AgenticMarketingRun::query()
        ->where('objective_id', $objective->id)
        ->where('payload->type', 'action_planning')
        ->firstOrFail();

    expect($planningRun->status)->toBe(AgenticMarketingRun::STATUS_COMPLETED)
        ->and($planningRun->items()->where('type', AgenticMarketingRunItem::TYPE_PLANNING)->where('status', AgenticMarketingRunItem::STATUS_COMPLETED)->exists())->toBeTrue()
        ->and(AgenticMarketingAction::query()->where('opportunity_id', $opportunity->id)->where('status', AgenticMarketingAction::STATUS_PROPOSED)->exists())->toBeTrue()
        ->and(AgenticMarketingAuditLog::query()->where('event', 'action.created')->exists())->toBeTrue();
});

it('records execution failures as failed runs and retryable audit events', function () {
    [$org, $workspace, $user] = makeRunAuditTenant('am-run-failure');
    $objective = makeRunAuditObjective($org, $workspace);
    $opportunity = AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'Create missing localized variant',
        'type' => AgenticMarketingOpportunityType::LocaleExpansion->value,
        'status' => 'open',
        'payload' => ['signals' => ['missing_locales' => ['nl']]],
    ]);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => $objective->id,
        'opportunity_id' => $opportunity->id,
        'action_type' => 'create_locale_variant',
        'status' => AgenticMarketingAction::STATUS_APPROVED,
        'payload' => ['target_locale' => 'nl'],
    ]);

    app(\App\Services\AgenticMarketing\AgenticMarketingActionExecutor::class)->execute($action, $user);

    $action->refresh();
    $executionRun = AgenticMarketingRun::query()
        ->where('objective_id', $objective->id)
        ->where('payload->type', 'action_execution')
        ->firstOrFail();

    expect($action->status)->toBe(AgenticMarketingAction::STATUS_FAILED)
        ->and($action->canRetry())->toBeTrue()
        ->and($action->error_message)->not->toBeEmpty()
        ->and($executionRun->status)->toBe(AgenticMarketingRun::STATUS_FAILED)
        ->and($executionRun->items()->where('type', AgenticMarketingRunItem::TYPE_EXECUTION)->where('status', AgenticMarketingRunItem::STATUS_FAILED)->exists())->toBeTrue()
        ->and(AgenticMarketingAuditLog::query()->where('event', 'action.execution_failed')->where('action_id', $action->id)->exists())->toBeTrue();

    expect($action->canRetry())->toBeTrue();
});
