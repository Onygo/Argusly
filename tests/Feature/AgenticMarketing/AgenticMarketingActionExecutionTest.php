<?php

use App\Jobs\AgenticMarketing\ExecuteAgenticMarketingActionJob;
use App\Enums\AgenticMarketingActionStatus;
use App\Enums\AgenticMarketingActionType;
use App\Enums\AgenticMarketingApprovalMode;
use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;
use App\Enums\ContentLifecycleStatus;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentTranslation;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionExecutor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['features.agentic_marketing' => true]);

    [$this->org, $this->workspace, $this->user] = makeAgenticMarketingTenant('agentic-marketing');

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);
});

function makeAgenticMarketingTenant(string $slug): array
{
    $org = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => $slug.' workspace',
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

function makeAgenticObjective(array $attributes = []): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create(array_merge([
        'organization_id' => test()->org->id,
        'workspace_id' => test()->workspace->id,
        'name' => 'Benelux AI visibility',
        'goal' => 'Increase AI visibility for Microsoft Teams analytics in the Benelux',
        'locale' => 'en',
        'audience' => 'B2B SaaS marketing leaders in the Benelux',
        'target_market' => 'Benelux',
        'languages' => ['en', 'nl'],
        'kpi_type' => 'ai_visibility',
        'monthly_credit_budget' => 250,
        'approval_mode' => 'manual',
        'status' => 'active',
    ], $attributes));
}

function makeAgenticAction(array $attributes = []): AgenticMarketingAction
{
    $objective = $attributes['objective'] ?? makeAgenticObjective();
    unset($attributes['objective']);

    return AgenticMarketingAction::query()->create(array_merge([
        'objective_id' => $objective->id,
        'action_type' => 'update_meta',
        'status' => AgenticMarketingAction::STATUS_APPROVED,
        'payload' => [
            'reason' => 'Improve answer visibility.',
            'recommendation' => 'Clarify the search promise and add entity coverage.',
        ],
    ], $attributes));
}

function makeAgenticOpportunity(array $attributes = []): AgenticMarketingOpportunity
{
    $objective = $attributes['objective'] ?? makeAgenticObjective();
    unset($attributes['objective']);

    return AgenticMarketingOpportunity::query()->create(array_merge([
        'objective_id' => $objective->id,
        'title' => 'Refresh comparison page for AI visibility',
        'type' => 'refresh',
        'status' => 'open',
    ], $attributes));
}

function makeAgenticRun(array $attributes = []): AgenticMarketingRun
{
    $objective = $attributes['objective'] ?? makeAgenticObjective();
    unset($attributes['objective']);

    return AgenticMarketingRun::query()->create(array_merge([
        'objective_id' => $objective->id,
        'status' => 'completed',
        'payload' => [],
    ], $attributes));
}

function makeAgenticContent(array $attributes = []): Content
{
    return Content::query()->create(array_merge([
        'workspace_id' => test()->workspace->id,
        'title' => 'Microsoft Teams analytics guide',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'language' => 'en',
    ], $attributes));
}

function makeAgenticClientSite(array $attributes = []): ClientSite
{
    return ClientSite::query()->create(array_merge([
        'workspace_id' => test()->workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Agentic Marketing site',
        'site_url' => 'https://agentic-marketing.example.test',
        'allowed_domains' => ['agentic-marketing.example.test'],
        'is_active' => true,
        'status' => 'active',
    ], $attributes));
}

it('lists objectives and shows an empty state', function () {
    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.index'))
        ->assertOk()
        ->assertSee('Create your first Agentic Marketing objective');

    $objective = makeAgenticObjective(['name' => 'Enterprise AEO growth']);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.index'))
        ->assertOk()
        ->assertSee('Enterprise AEO growth')
        ->assertSee('New objective');
});

it('translates the agentic marketing command center when Dutch is selected', function () {
    $objective = makeAgenticObjective(['name' => 'NL command center objective']);
    makeAgenticOpportunity(['objective' => $objective, 'title' => 'Kans voor AI zichtbaarheid']);
    makeAgenticAction(['objective' => $objective, 'status' => AgenticMarketingAction::STATUS_PROPOSED]);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.index', ['lang' => 'nl']))
        ->assertOk()
        ->assertSee('Monitor doelstellingen, opportunity intelligence')
        ->assertSee('Nieuwe doelstelling')
        ->assertSee('Doelstellingen')
        ->assertSee('Actiegereedheid')
        ->assertSee('Impact gemeten')
        ->assertSee('Doelstellingenoverzicht')
        ->assertSee('Actiewachtrij')
        ->assertSee('Filter voorgesteld en goedgekeurd werk')
        ->assertDontSee('Objectives Overview');
});

it('creates an objective with validated strategy fields', function () {
    $site = makeAgenticClientSite();

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.objectives.create'))
        ->assertOk()
        ->assertSee('New objective')
        ->assertSee('Approval mode');

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.objectives.store'), [
            'name' => 'Teams analytics demand capture',
            'goal' => 'Increase AI answer visibility for Teams analytics buying journeys.',
            'kpi_type' => 'ai_visibility',
            'locale' => 'nl',
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $site->id,
            'audience' => 'RevOps and marketing teams in the Benelux',
            'competitors' => "Gong\nClari",
            'approval_mode' => 'manual',
            'monthly_credit_budget' => 500,
            'status' => 'active',
        ])
        ->assertRedirect();

    $objective = AgenticMarketingObjective::query()->where('name', 'Teams analytics demand capture')->first();

    expect($objective)->not->toBeNull()
        ->and($objective->organization_id)->toBe($this->org->id)
        ->and($objective->workspace_id)->toBe($this->workspace->id)
        ->and($objective->client_site_id)->toBe($site->id)
        ->and($objective->locale)->toBe('nl')
        ->and($objective->languages)->toBe(['nl'])
        ->and($objective->competitors)->toBe(['Gong', 'Clari'])
        ->and($objective->monthly_credit_budget)->toBe(500);
});

it('shows and updates an objective dashboard', function () {
    $objective = makeAgenticObjective(['name' => 'Initial objective']);
    makeAgenticOpportunity(['objective' => $objective, 'title' => 'Refresh AI visibility page']);
    makeAgenticRun(['objective' => $objective, 'status' => 'completed']);
    makeAgenticAction(['objective' => $objective, 'action_type' => 'update_meta']);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.objectives.show', $objective))
        ->assertOk()
        ->assertSee('Initial objective')
        ->assertSee('Refresh AI visibility page')
        ->assertSee('Actions')
        ->assertSee('Find actions')
        ->assertSee('Runs');

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.objectives.edit', $objective))
        ->assertOk()
        ->assertSee('Edit objective')
        ->assertSee('Initial objective');

    $this->actingAs($this->user)
        ->put(route('app.agentic-marketing.objectives.update', $objective), [
            'name' => 'Updated objective',
            'goal' => 'Improve AI visibility for updated segment.',
            'kpi_type' => 'organic_traffic',
            'locale' => 'en',
            'workspace_id' => $this->workspace->id,
            'client_site_id' => null,
            'audience' => 'Enterprise marketers',
            'competitors' => 'Mutiny',
            'approval_mode' => 'policy_engine',
            'monthly_credit_budget' => 750,
            'status' => 'paused',
        ])
        ->assertRedirect(route('app.agentic-marketing.objectives.show', $objective));

    $objective->refresh();
    expect($objective->name)->toBe('Updated objective')
        ->and($objective->status)->toBe('paused')
        ->and($objective->approval_mode)->toBe('policy_engine')
        ->and($objective->kpi_type)->toBe('organic_traffic')
        ->and($objective->competitors)->toBe(['Mutiny']);
});

it('translates inline objective dashboard copy when Dutch is selected', function () {
    $objective = makeAgenticObjective(['name' => 'Dutch dashboard objective']);
    makeAgenticOpportunity(['objective' => $objective, 'title' => 'AI zichtbaarheid verbeteren']);
    makeAgenticRun(['objective' => $objective, 'status' => 'completed']);
    makeAgenticAction(['objective' => $objective, 'status' => AgenticMarketingAction::STATUS_PROPOSED]);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.objectives.show', $objective).'?lang=nl')
        ->assertOk()
        ->assertSee('Dutch dashboard objective')
        ->assertSee('Acties vinden')
        ->assertSee('Focuswachtrij')
        ->assertSee('Doelstellingsoverzicht')
        ->assertSee('Open kansen')
        ->assertSee('Opportunity-mix')
        ->assertSee('Signalen')
        ->assertSee('Topkansen')
        ->assertSee('Activiteit en audittrail')
        ->assertSee('Goedkeuren')
        ->assertDontSee('Focus Queue');
});

it('translates inline action detail copy when Dutch is selected', function () {
    $objective = makeAgenticObjective(['name' => 'Dutch action objective']);
    $opportunity = makeAgenticOpportunity([
        'objective' => $objective,
        'title' => 'SEO-indexeerbaarheid verbeteren',
        'payload' => [
            'score_explanation' => [
                'summary' => 'Recommended because SEO/indexability issue signals reduce discoverability.',
                'reasons' => ['SEO/indexability checks found stored issue signals.'],
            ],
        ],
    ]);
    $action = makeAgenticAction([
        'objective' => $objective,
        'opportunity_id' => $opportunity->id,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'action_type' => 'add_schema',
        'estimated_credits' => 5,
        'payload' => [
            'recommendation' => 'Prepare schema markup recommendations for review. Recommended because SEO/indexability issue signals reduce discoverability.',
            'planning' => [
                'risk_level' => 'low',
                'approval_required' => true,
                'approval_reason' => 'Manual approval mode is proposal-only by default.',
                'prerequisites' => ['met' => true],
            ],
        ],
    ]);
    \App\Models\AgenticMarketingAuditLog::query()->create([
        'organization_id' => $this->org->id,
        'objective_id' => $objective->id,
        'action_id' => $action->id,
        'event' => 'action.created',
        'subject_type' => AgenticMarketingAction::class,
        'subject_id' => $action->id,
        'metadata' => [],
    ]);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.actions.show', $action).'?lang=nl')
        ->assertOk()
        ->assertSee('Kosten')
        ->assertSee('Voorwaarden')
        ->assertSee('Klaar')
        ->assertSee('Niet gereserveerd')
        ->assertSee('Wat werd voorgesteld?')
        ->assertSee('Wat veranderde er na uitvoering?')
        ->assertSee('Deze actie is nog niet voltooid.')
        ->assertSee('Tijdlijn')
        ->assertSee('Waarom deze actie?')
        ->assertSee('Fout &amp; opnieuw proberen', false)
        ->assertDontSee('What was proposed?');
});

it('runs an objective scan and creates reviewable actions from stored signals', function () {
    $objective = makeAgenticObjective(['name' => 'Scan objective']);
    makeAgenticContent([
        'title' => 'Stale AEO guide',
        'status' => 'published',
        'publish_status' => 'published',
        'lifecycle_stage' => ContentLifecycleStatus::REFRESH_NEEDED->value,
        'freshness_score' => 25,
        'content_health_score' => 55,
        'optimization_opportunity_score' => 80,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.objectives.scan', $objective))
        ->assertRedirect(route('app.agentic-marketing.objectives.show', $objective));

    expect(AgenticMarketingOpportunity::query()->where('objective_id', $objective->id)->count())->toBeGreaterThan(0);
    expect(AgenticMarketingAction::query()
        ->where('objective_id', $objective->id)
        ->where('status', AgenticMarketingAction::STATUS_PROPOSED)
        ->count())->toBeGreaterThan(0);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.objectives.show', $objective))
        ->assertOk()
        ->assertSee('Stale AEO guide')
        ->assertSee('Approve');
});

it('deletes an objective only when no work is linked', function () {
    $objective = makeAgenticObjective(['name' => 'Temporary objective']);

    $this->actingAs($this->user)
        ->delete(route('app.agentic-marketing.objectives.destroy', $objective))
        ->assertRedirect(route('app.agentic-marketing.index'));

    expect(AgenticMarketingObjective::query()->whereKey($objective->id)->exists())->toBeFalse();

    $linked = makeAgenticObjective(['name' => 'Linked objective']);
    makeAgenticAction(['objective' => $linked]);

    $this->actingAs($this->user)
        ->delete(route('app.agentic-marketing.objectives.destroy', $linked))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(AgenticMarketingObjective::query()->whereKey($linked->id)->exists())->toBeTrue();
});

it('validates objective fields and tenant scoped workspace and site references', function () {
    [, $otherWorkspace] = makeAgenticMarketingTenant('objective-validation-other');
    $otherSite = makeAgenticClientSite([
        'workspace_id' => $otherWorkspace->id,
        'site_url' => 'https://objective-validation-other.example.test',
    ]);

    $this->actingAs($this->user)
        ->from(route('app.agentic-marketing.objectives.create'))
        ->post(route('app.agentic-marketing.objectives.store'), [
            'name' => '',
            'goal' => '',
            'kpi_type' => 'not-a-kpi',
            'locale' => 'xx',
            'workspace_id' => $otherWorkspace->id,
            'client_site_id' => $otherSite->id,
            'approval_mode' => 'autonomous',
            'monthly_credit_budget' => -1,
            'status' => 'missing',
        ])
        ->assertRedirect(route('app.agentic-marketing.objectives.create'))
        ->assertSessionHasErrors(['name', 'goal', 'kpi_type', 'locale', 'workspace_id', 'approval_mode', 'monthly_credit_budget', 'status']);

    $this->actingAs($this->user)
        ->from(route('app.agentic-marketing.objectives.create'))
        ->post(route('app.agentic-marketing.objectives.store'), [
            'name' => 'Invalid site objective',
            'goal' => 'This should reject the site.',
            'kpi_type' => 'ai_visibility',
            'locale' => 'en',
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $otherSite->id,
            'approval_mode' => 'manual',
            'monthly_credit_budget' => 10,
            'status' => 'active',
        ])
        ->assertRedirect(route('app.agentic-marketing.objectives.create'))
        ->assertSessionHasErrors(['client_site_id']);
});

it('stores objective goals longer than the default varchar length', function () {
    $goal = 'Increase PublishLayer visibility in AI driven search and answer engines by consistently publishing authoritative, entity rich content around Agentic Marketing, AI visibility, Answer Engine Optimization, content automation, and AI workflows. Build topical authority and generate qualified demo and signup intent.';

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.objectives.store'), [
            'name' => 'PublishLayer AI Visibility Growth',
            'goal' => $goal,
            'kpi_type' => 'ai_visibility',
            'locale' => 'en',
            'workspace_id' => $this->workspace->id,
            'approval_mode' => 'manual',
            'monthly_credit_budget' => 100,
            'status' => 'active',
        ])
        ->assertRedirect();

    expect(AgenticMarketingObjective::query()
        ->where('name', 'PublishLayer AI Visibility Growth')
        ->value('goal'))->toBe($goal);
});

it('prevents cross organization objective access and mutation', function () {
    [$otherOrg, $otherWorkspace] = makeAgenticMarketingTenant('objective-auth-other');
    $objective = makeAgenticObjective([
        'organization_id' => $otherOrg->id,
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.objectives.show', $objective))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.objectives.edit', $objective))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->put(route('app.agentic-marketing.objectives.update', $objective), [
            'name' => 'Hijack',
            'goal' => 'Nope',
            'kpi_type' => 'ai_visibility',
            'locale' => 'en',
            'workspace_id' => $otherWorkspace->id,
            'approval_mode' => 'manual',
            'status' => 'active',
        ])
        ->assertForbidden();
});

it('normalizes agentic marketing enums and stores hashes on records', function () {
    expect(AgenticMarketingOpportunityType::values())->toContain('refresh')
        ->and(AgenticMarketingOpportunityStatus::values())->toContain('open')
        ->and(AgenticMarketingActionType::values())->toContain('update_meta')
        ->and(AgenticMarketingActionStatus::values())->toContain(AgenticMarketingAction::STATUS_APPROVED)
        ->and(AgenticMarketingApprovalMode::values())->toContain('manual');

    $content = makeAgenticContent();
    $opportunity = makeAgenticOpportunity([
        'type' => AgenticMarketingOpportunityType::Refresh->value,
        'payload' => ['content_id' => $content->id, 'signals' => ['b' => 2, 'a' => 1]],
    ]);
    $action = makeAgenticAction([
        'opportunity_id' => $opportunity->id,
        'action_type' => AgenticMarketingActionType::UpdateMeta->value,
        'payload' => ['seo_title' => 'AEO guide', 'reason' => 'Improve clarity'],
    ]);

    expect($opportunity->payload_hash)->toHaveLength(64)
        ->and($opportunity->dedupe_hash)->toHaveLength(64)
        ->and($opportunity->open_dedupe_hash)->toBe($opportunity->dedupe_hash)
        ->and($opportunity->content_id)->toBe($content->id)
        ->and($action->payload_hash)->toHaveLength(64)
        ->and($action->dedupe_hash)->toHaveLength(64)
        ->and($action->open_dedupe_hash)->toBe($action->dedupe_hash);
});

it('reuses duplicate open opportunities for the same objective content type and payload hash', function () {
    $objective = makeAgenticObjective();
    $content = makeAgenticContent();
    $payload = [
        'content_id' => $content->id,
        'reason' => 'Refresh decayed content.',
        'signals' => ['score' => 42, 'trend' => 'down'],
    ];

    $first = AgenticMarketingOpportunity::createOrReuseOpen([
        'objective_id' => $objective->id,
        'title' => 'Refresh decayed Teams analytics content',
        'type' => AgenticMarketingOpportunityType::Refresh->value,
        'priority_score' => 80,
        'payload' => $payload,
    ]);
    $second = AgenticMarketingOpportunity::createOrReuseOpen([
        'objective_id' => $objective->id,
        'title' => 'Duplicate detector output',
        'type' => AgenticMarketingOpportunityType::Refresh->value,
        'priority_score' => 40,
        'payload' => [
            'signals' => ['trend' => 'down', 'score' => 42],
            'reason' => 'Refresh decayed content.',
            'content_id' => $content->id,
        ],
    ]);

    expect($second->id)->toBe($first->id)
        ->and(AgenticMarketingOpportunity::query()->where('objective_id', $objective->id)->count())->toBe(1);

    $first->forceFill(['status' => AgenticMarketingOpportunityStatus::Completed->value])->save();

    $third = AgenticMarketingOpportunity::createOrReuseOpen([
        'objective_id' => $objective->id,
        'title' => 'Refresh after completed opportunity',
        'type' => AgenticMarketingOpportunityType::Refresh->value,
        'priority_score' => 80,
        'payload' => $payload,
    ]);

    expect($third->id)->not->toBe($first->id)
        ->and(AgenticMarketingOpportunity::query()->where('objective_id', $objective->id)->count())->toBe(2);
});

it('reuses duplicate open actions for the same opportunity action type and payload hash', function () {
    $opportunity = makeAgenticOpportunity(['type' => AgenticMarketingOpportunityType::Metadata->value]);
    $payload = [
        'seo_title' => 'AI visibility for Teams analytics',
        'reason' => 'Clarify answer promise.',
    ];

    $first = AgenticMarketingAction::createOrReuseOpen([
        'objective_id' => $opportunity->objective_id,
        'opportunity_id' => $opportunity->id,
        'action_type' => AgenticMarketingActionType::UpdateMeta->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'payload' => $payload,
    ]);
    $second = AgenticMarketingAction::createOrReuseOpen([
        'objective_id' => $opportunity->objective_id,
        'opportunity_id' => $opportunity->id,
        'action_type' => AgenticMarketingActionType::UpdateMeta->value,
        'status' => AgenticMarketingAction::STATUS_APPROVED,
        'payload' => [
            'reason' => 'Clarify answer promise.',
            'seo_title' => 'AI visibility for Teams analytics',
        ],
    ]);

    expect($second->id)->toBe($first->id)
        ->and(AgenticMarketingAction::query()->where('opportunity_id', $opportunity->id)->count())->toBe(1);

    $first->forceFill(['status' => AgenticMarketingAction::STATUS_COMPLETED])->save();

    $third = AgenticMarketingAction::createOrReuseOpen([
        'objective_id' => $opportunity->objective_id,
        'opportunity_id' => $opportunity->id,
        'action_type' => AgenticMarketingActionType::UpdateMeta->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'payload' => $payload,
    ]);

    expect($third->id)->not->toBe($first->id)
        ->and(AgenticMarketingAction::query()->where('opportunity_id', $opportunity->id)->count())->toBe(2);
});

it('enforces open opportunity and action dedupe at the database index layer', function () {
    $objective = makeAgenticObjective();
    $content = makeAgenticContent();
    $payload = ['content_id' => $content->id, 'reason' => 'Same signal.'];

    AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'First open opportunity',
        'type' => AgenticMarketingOpportunityType::Refresh->value,
        'payload' => $payload,
    ]);

    expect(fn () => AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'Duplicate open opportunity',
        'type' => AgenticMarketingOpportunityType::Refresh->value,
        'payload' => $payload,
    ]))->toThrow(QueryException::class);

    $opportunity = AgenticMarketingOpportunity::query()->where('objective_id', $objective->id)->firstOrFail();

    AgenticMarketingAction::query()->create([
        'objective_id' => $objective->id,
        'opportunity_id' => $opportunity->id,
        'action_type' => AgenticMarketingActionType::UpdateMeta->value,
        'payload' => ['seo_title' => 'Same title'],
    ]);

    expect(fn () => AgenticMarketingAction::query()->create([
        'objective_id' => $objective->id,
        'opportunity_id' => $opportunity->id,
        'action_type' => AgenticMarketingActionType::UpdateMeta->value,
        'payload' => ['seo_title' => 'Same title'],
    ]))->toThrow(QueryException::class);
});

it('queues execution for an approved action', function () {
    Bus::fake();
    $action = makeAgenticAction();

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.actions.execute', $action))
        ->assertRedirect()
        ->assertSessionHas('status');

    Bus::assertDispatched(ExecuteAgenticMarketingActionJob::class, fn ($job) => $job->actionId === $action->id && filled($job->claimId));

    $action->refresh();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_RUNNING)
        ->and($action->execution_claim_id)->not->toBeNull();
});

it('does not dispatch duplicate jobs for duplicate execute clicks', function () {
    Bus::fake();
    $action = makeAgenticAction();

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.actions.execute', $action))
        ->assertRedirect()
        ->assertSessionHas('status');

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.actions.execute', $action->fresh()))
        ->assertRedirect()
        ->assertSessionHas('status');

    Bus::assertDispatchedTimes(ExecuteAgenticMarketingActionJob::class, 1);

    expect($action->fresh()->status)->toBe(AgenticMarketingAction::STATUS_RUNNING);
});

it('does not execute a proposed action', function () {
    Bus::fake();
    $action = makeAgenticAction(['status' => AgenticMarketingAction::STATUS_PROPOSED]);

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.actions.execute', $action))
        ->assertRedirect()
        ->assertSessionHas('status');

    Bus::assertNotDispatched(ExecuteAgenticMarketingActionJob::class);
    expect($action->fresh()->status)->toBe(AgenticMarketingAction::STATUS_PROPOSED);
});

it('does not execute a completed action twice', function () {
    Bus::fake();
    $action = makeAgenticAction(['status' => AgenticMarketingAction::STATUS_COMPLETED]);

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.actions.execute', $action))
        ->assertRedirect()
        ->assertSessionHas('status');

    Bus::assertNotDispatched(ExecuteAgenticMarketingActionJob::class);
});

it('allows a failed action to be retried', function () {
    Bus::fake();
    $action = makeAgenticAction([
        'status' => AgenticMarketingAction::STATUS_FAILED,
        'error_message' => 'Temporary safe execution failure.',
        'failed_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.actions.retry', $action))
        ->assertRedirect()
        ->assertSessionHas('status');

    Bus::assertDispatched(ExecuteAgenticMarketingActionJob::class, fn ($job) => $job->actionId === $action->id && filled($job->claimId));
    expect($action->fresh()->status)->toBe(AgenticMarketingAction::STATUS_RUNNING)
        ->and($action->fresh()->error_message)->toBeNull();
});

it('uses a unique queue lock per action', function () {
    $job = new ExecuteAgenticMarketingActionJob('action-123', $this->user->id, 'claim-123');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('agentic-marketing-action:action-123')
        ->and($job->middleware())->not->toBeEmpty();
});

it('stores a readable error when execution fails', function () {
    $action = makeAgenticAction(['action_type' => 'unknown_safe_action']);

    app(AgenticMarketingActionExecutor::class)->execute($action, $this->user);

    $action->refresh();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_FAILED)
        ->and($action->error_message)->toContain('Unsupported Agentic Marketing action type')
        ->and(data_get($action->result, 'summary'))->toBe('Action failed before making changes.');
});

it('creates a safe refresh result or review draft', function () {
    $content = makeAgenticContent();
    $action = makeAgenticAction([
        'action_type' => 'refresh_article',
        'content_id' => $content->id,
        'payload' => ['content_id' => $content->id, 'recommendation' => 'Refresh answer quality.'],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $this->user);

    $action->refresh();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_COMPLETED)
        ->and(data_get($action->result, 'summary'))->toContain('Refresh recommendation')
        ->and($content->fresh()->status)->not->toBe('published');
});

it('creates draft content for create_article without publishing', function () {
    $action = makeAgenticAction([
        'action_type' => 'create_article',
        'payload' => [
            'workspace_id' => $this->workspace->id,
            'title' => 'AI visibility for Teams analytics',
            'locale' => 'en',
        ],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $this->user);

    $action->refresh();
    $content = Content::query()->find(data_get($action->result, 'created_content_id'));

    expect($action->status)->toBe(AgenticMarketingAction::STATUS_COMPLETED)
        ->and($content)->not->toBeNull()
        ->and($content->status)->toBe('draft')
        ->and((bool) $content->auto_publish)->toBeFalse();
});

it('creates a populated brief and editable draft for create_article when a site is available', function () {
    $site = makeAgenticClientSite();
    $objective = makeAgenticObjective(['client_site_id' => $site->id]);
    $opportunity = makeAgenticOpportunity([
        'objective' => $objective,
        'title' => 'LLMs.txt Guide',
        'type' => AgenticMarketingOpportunityType::NewArticle->value,
        'payload' => [
            'client_site_id' => (string) $site->id,
            'target_audience' => 'B2B SaaS marketing leaders',
            'funnel_stage' => 'consideration',
            'primary_search_intent' => 'informational',
            'suggested_cta' => 'Book a demo',
        ],
    ]);
    $action = makeAgenticAction([
        'objective' => $objective,
        'opportunity_id' => $opportunity->id,
        'action_type' => 'create_article',
        'payload' => [
            'workspace_id' => $this->workspace->id,
            'title' => 'LLMs.txt Guide',
            'locale' => 'en',
            'primary_keyword' => 'llms.txt',
            'target_audience' => 'B2B SaaS marketing leaders',
            'funnel_stage' => 'consideration',
            'search_intent' => 'informational',
            'angle' => 'Explain how llms.txt helps AI systems understand a site.',
            'suggested_cta' => 'Book a demo',
            'suggested_schema' => 'Article',
            'reason' => 'Create the LLMs.txt Guide asset from the campaign cluster.',
            'recommendation' => 'Create a draft article for review.',
        ],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $this->user);

    $action->refresh();
    $content = Content::query()->findOrFail(data_get($action->result, 'created_content_id'));
    $brief = Brief::query()->where('content_id', $content->id)->firstOrFail();
    $draft = Draft::query()->findOrFail(data_get($action->result, 'created_draft_id'));

    expect($content->client_site_id)->toBe($site->id)
        ->and($content->status)->toBe('draft')
        ->and($brief->primary_keyword)->toBe('llms.txt')
        ->and($brief->target_audience)->toBe('B2B SaaS marketing leaders')
        ->and($brief->search_intent)->toBe('informational')
        ->and($brief->key_points)->not->toBeEmpty()
        ->and($draft->content_id)->toBe($content->id)
        ->and($draft->brief_id)->toBe($brief->id)
        ->and($draft->content_html)->toContain('Why it matters for AI visibility')
        ->and($draft->content_html)->not->toContain('Draft outline')
        ->and(data_get($draft->meta, 'briefing_complete'))->toBeTrue();
});

it('does not create duplicate content when duplicate workers handle the same claimed action', function () {
    $claimId = (string) Str::uuid();
    $action = makeAgenticAction([
        'action_type' => 'create_article',
        'status' => AgenticMarketingAction::STATUS_RUNNING,
        'execution_claim_id' => $claimId,
        'execution_claimed_at' => now(),
        'started_at' => null,
        'payload' => [
            'workspace_id' => $this->workspace->id,
            'title' => 'Parallel safe AI visibility draft',
            'locale' => 'en',
        ],
    ]);

    $before = Content::query()->count();

    app(AgenticMarketingActionExecutor::class)->execute($action, $this->user, $claimId);
    app(AgenticMarketingActionExecutor::class)->execute($action->fresh(), $this->user, $claimId);

    $action->refresh();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_COMPLETED)
        ->and(Content::query()->count())->toBe($before + 1)
        ->and(Content::query()->where('title', 'Parallel safe AI visibility draft')->count())->toBe(1);
});

it('does not create duplicate content when a completed action is retried by a stale job', function () {
    $action = makeAgenticAction([
        'action_type' => 'create_article',
        'payload' => [
            'workspace_id' => $this->workspace->id,
            'title' => 'Stale job guarded AI visibility draft',
            'locale' => 'en',
        ],
    ]);

    $before = Content::query()->count();

    app(AgenticMarketingActionExecutor::class)->execute($action, $this->user);
    app(AgenticMarketingActionExecutor::class)->execute($action->fresh(), $this->user);

    expect(Content::query()->count())->toBe($before + 1)
        ->and(Content::query()->where('title', 'Stale job guarded AI visibility draft')->count())->toBe(1);
});

it('respects existing locale translation locks', function () {
    $content = makeAgenticContent();
    ContentTranslation::query()->create([
        'content_id' => $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_PROCESSING,
        'processing_locked_at' => now(),
    ]);

    $action = makeAgenticAction([
        'action_type' => 'create_locale_variant',
        'content_id' => $content->id,
        'payload' => ['content_id' => $content->id, 'target_locale' => 'nl'],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $this->user);

    expect($action->fresh()->status)->toBe(AgenticMarketingAction::STATUS_FAILED)
        ->and($action->fresh()->error_message)->toContain('already queued or processing');
});

it('prevents cross organization action execution', function () {
    [$otherOrg, $otherWorkspace, $otherUser] = makeAgenticMarketingTenant('other-agentic-marketing');
    $objective = makeAgenticObjective([
        'organization_id' => $otherOrg->id,
        'workspace_id' => $otherWorkspace->id,
    ]);
    $action = makeAgenticAction([
        'objective' => $objective,
        'status' => AgenticMarketingAction::STATUS_APPROVED,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.actions.execute', $action))
        ->assertForbidden();
});

it('denies cross organization access through agentic marketing policies', function () {
    [$otherOrg, $otherWorkspace] = makeAgenticMarketingTenant('policy-other-agentic-marketing');
    $objective = makeAgenticObjective([
        'organization_id' => $otherOrg->id,
        'workspace_id' => $otherWorkspace->id,
    ]);
    $opportunity = makeAgenticOpportunity(['objective' => $objective]);
    $run = makeAgenticRun(['objective' => $objective]);
    $action = makeAgenticAction(['objective' => $objective]);

    expect(Gate::forUser($this->user)->allows('view', $objective))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('view', $opportunity))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('view', $run))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('execute', $action))->toBeFalse();
});

it('blocks agentic marketing routes when the feature flag is disabled', function () {
    config(['features.agentic_marketing' => false]);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.index'))
        ->assertNotFound();
});

it('executor refuses content references outside the objective organization', function () {
    [, $otherWorkspace] = makeAgenticMarketingTenant('executor-other-content');
    $otherContent = Content::query()->create([
        'workspace_id' => $otherWorkspace->id,
        'title' => 'Other organization content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'language' => 'en',
    ]);

    $action = makeAgenticAction([
        'action_type' => 'update_meta',
        'payload' => [
            'content_id' => $otherContent->id,
            'seo_title' => 'Should not be accepted',
        ],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $this->user);

    $action->refresh();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_FAILED)
        ->and($action->error_message)->toContain('Referenced content is outside this organization');
});

it('executor refuses site references outside the objective workspace', function () {
    [, $otherWorkspace] = makeAgenticMarketingTenant('executor-other-site');
    $otherSite = makeAgenticClientSite([
        'workspace_id' => $otherWorkspace->id,
        'name' => 'Other organization site',
        'site_url' => 'https://other-agentic-marketing.example.test',
    ]);

    $action = makeAgenticAction([
        'action_type' => 'create_article',
        'payload' => [
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $otherSite->id,
            'title' => 'Tenant safe article',
            'locale' => 'en',
        ],
    ]);

    $before = Content::query()->count();

    app(AgenticMarketingActionExecutor::class)->execute($action, $this->user);

    $action->refresh();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_FAILED)
        ->and($action->error_message)->toContain('Referenced site is outside this organization')
        ->and(Content::query()->count())->toBe($before);
});
