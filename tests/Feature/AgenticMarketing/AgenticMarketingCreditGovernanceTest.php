<?php

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\CreditReservation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionExecutor;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeAgenticBillingTenant(string $slug = 'agentic-billing'): array
{
    $organization = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => $slug . ' workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Agentic billing site',
        'site_url' => 'https://' . $slug . '.example.test',
        'allowed_domains' => [$slug . '.example.test'],
        'is_active' => true,
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'admin',
        'active' => true,
        'approved_at' => now(),
    ]);

    return compact('organization', 'workspace', 'site', 'user');
}

function makeAgenticBillingAction(array $attributes = []): AgenticMarketingAction
{
    $setup = $attributes['setup'] ?? makeAgenticBillingTenant();
    unset($attributes['setup']);

    $objective = $attributes['objective'] ?? AgenticMarketingObjective::query()->create([
        'organization_id' => $setup['organization']->id,
        'workspace_id' => $setup['workspace']->id,
        'client_site_id' => $setup['site']->id,
        'name' => 'Credit governed objective',
        'goal' => 'Grow answer visibility without exceeding monthly credits.',
        'locale' => 'en',
        'kpi_type' => 'ai_visibility',
        'approval_mode' => $attributes['approval_mode'] ?? 'manual',
        'monthly_credit_budget' => 100,
        'status' => 'active',
    ]);
    unset($attributes['approval_mode']);
    unset($attributes['objective']);

    $opportunity = AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'Improve metadata for answer visibility',
        'type' => 'metadata',
        'status' => 'open',
        'payload' => ['score_explanation' => ['summary' => 'Metadata is missing clear answer framing.']],
    ]);

    return AgenticMarketingAction::query()->create(array_merge([
        'objective_id' => $objective->id,
        'opportunity_id' => $opportunity->id,
        'action_type' => 'update_meta',
        'status' => AgenticMarketingAction::STATUS_APPROVED,
        'estimated_credits' => 10,
        'payload' => [
            'client_site_id' => (string) $setup['site']->id,
            'workspace_id' => (string) $setup['workspace']->id,
            'recommendation' => 'Clarify the search promise.',
            'planning' => [
                'estimated_credits' => 10,
                'risk_level' => 'low',
                'approval_required' => true,
            ],
        ],
    ], $attributes));
}

it('blocks execution before reservation when the objective monthly budget is exceeded', function () {
    $setup = makeAgenticBillingTenant('agentic-budget-exceeded');
    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $setup['organization']->id,
        'workspace_id' => $setup['workspace']->id,
        'client_site_id' => $setup['site']->id,
        'name' => 'Tiny budget objective',
        'goal' => 'Keep AM inside a tiny budget.',
        'locale' => 'en',
        'kpi_type' => 'ai_visibility',
        'approval_mode' => 'policy_engine',
        'monthly_credit_budget' => 5,
        'status' => 'active',
    ]);
    $action = makeAgenticBillingAction(['setup' => $setup, 'objective' => $objective, 'estimated_credits' => 10]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user'], (string) Str::uuid());

    $action->refresh();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_FAILED)
        ->and($action->credit_status)->toBe('budget_exceeded')
        ->and($action->credit_reservation_id)->toBeNull()
        ->and($action->error_message)->toContain('monthly budget exceeded')
        ->and(CreditReservation::query()->count())->toBe(0);
});

it('fails safely when credit reservation cannot be created', function () {
    $setup = makeAgenticBillingTenant('agentic-reservation-failure');
    $action = makeAgenticBillingAction(['setup' => $setup, 'approval_mode' => 'policy_engine', 'estimated_credits' => 15]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user'], (string) Str::uuid());

    $action->refresh();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_FAILED)
        ->and($action->credit_status)->toBe('failed')
        ->and($action->credit_reservation_id)->toBeNull()
        ->and($action->error_message)->toContain('Insufficient credits')
        ->and(CreditReservation::query()->count())->toBe(0);
});

it('does not reserve or capture credits for proposal-only manual actions', function () {
    $setup = makeAgenticBillingTenant('agentic-proposal-free');
    app(CreditWalletService::class)->addWorkspaceCredits(
        workspaceId: (string) $setup['workspace']->id,
        amount: 30,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['trigger' => 'agentic_marketing_proposal_test'],
        sourceType: Organization::class,
        sourceId: (string) $setup['organization']->id,
        idempotencyKey: 'agentic-marketing-proposal-free',
        preferredClientSiteId: (string) $setup['site']->id,
    );

    $action = makeAgenticBillingAction([
        'setup' => $setup,
        'action_type' => 'add_schema',
        'estimated_credits' => 5,
        'payload' => [
            'client_site_id' => (string) $setup['site']->id,
            'workspace_id' => (string) $setup['workspace']->id,
            'recommendation' => 'Prepare schema proposal.',
            'planning' => [
                'estimated_credits' => 5,
                'risk_level' => 'low',
                'approval_required' => true,
            ],
        ],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user'], (string) Str::uuid());

    $action->refresh();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_COMPLETED)
        ->and($action->credit_status)->toBe('skipped')
        ->and($action->credits_reserved)->toBeNull()
        ->and($action->credits_captured)->toBeNull()
        ->and(CreditReservation::query()->count())->toBe(0)
        ->and(app(CreditWalletService::class)->getAvailableForClientSite((string) $setup['site']->id))->toBe(30);
});

it('does not count proposed action estimates as committed budget during execution', function () {
    $setup = makeAgenticBillingTenant('agentic-budget-forecast');
    app(CreditWalletService::class)->addWorkspaceCredits(
        workspaceId: (string) $setup['workspace']->id,
        amount: 80,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['trigger' => 'agentic_marketing_forecast_test'],
        sourceType: Organization::class,
        sourceId: (string) $setup['organization']->id,
        idempotencyKey: 'agentic-marketing-forecast-success',
        preferredClientSiteId: (string) $setup['site']->id,
    );

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $setup['organization']->id,
        'workspace_id' => $setup['workspace']->id,
        'client_site_id' => $setup['site']->id,
        'name' => 'Forecast-heavy objective',
        'goal' => 'Allow one action while proposed work remains only forecast.',
        'locale' => 'en',
        'kpi_type' => 'ai_visibility',
        'approval_mode' => 'policy_engine',
        'monthly_credit_budget' => 40,
        'status' => 'active',
    ]);

    AgenticMarketingAction::query()->create([
        'objective_id' => $objective->id,
        'action_type' => 'create_article',
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 50,
        'payload' => [
            'client_site_id' => (string) $setup['site']->id,
            'workspace_id' => (string) $setup['workspace']->id,
            'recommendation' => 'Draft a supporting article later.',
            'planning' => [
                'estimated_credits' => 50,
                'risk_level' => 'medium',
                'approval_required' => true,
            ],
        ],
    ]);

    $action = makeAgenticBillingAction([
        'setup' => $setup,
        'objective' => $objective,
        'estimated_credits' => 10,
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user'], (string) Str::uuid());

    $action->refresh();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_COMPLETED)
        ->and($action->credit_status)->toBe(CreditReservation::STATUS_CAPTURED)
        ->and($action->credits_captured)->toBe(10);
});

it('reserves credits before execution and captures them after success', function () {
    $setup = makeAgenticBillingTenant('agentic-capture-success');
    app(CreditWalletService::class)->addWorkspaceCredits(
        workspaceId: (string) $setup['workspace']->id,
        amount: 60,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['trigger' => 'agentic_marketing_test'],
        sourceType: Organization::class,
        sourceId: (string) $setup['organization']->id,
        idempotencyKey: 'agentic-marketing-capture-success',
        preferredClientSiteId: (string) $setup['site']->id,
    );

    $action = makeAgenticBillingAction(['setup' => $setup, 'approval_mode' => 'policy_engine', 'estimated_credits' => 12]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user'], (string) Str::uuid());

    $action->refresh();
    $reservation = CreditReservation::query()->find($action->credit_reservation_id);

    expect($action->status)->toBe(AgenticMarketingAction::STATUS_COMPLETED)
        ->and($action->credit_status)->toBe(CreditReservation::STATUS_CAPTURED)
        ->and($action->credits_reserved)->toBe(12)
        ->and($action->credits_captured)->toBe(12)
        ->and($reservation)->not->toBeNull()
        ->and($reservation->status)->toBe(CreditReservation::STATUS_CAPTURED)
        ->and(app(CreditWalletService::class)->getAvailableForClientSite((string) $setup['site']->id))->toBe(48);
});
