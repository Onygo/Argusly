<?php

use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeActionPlannerTenant(string $slug = 'am-planner'): array
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
        'enabled_content_languages' => ['en', 'nl', 'de'],
        'default_content_language' => 'en',
    ]);

    return [$org, $workspace];
}

function makeActionPlannerObjective(Organization $org, Workspace $workspace, array $attributes = []): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create(array_merge([
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
        'name' => 'Action planner objective',
        'goal' => 'Turn opportunities into supervised action proposals.',
        'locale' => 'en',
        'languages' => ['en', 'nl', 'de'],
        'kpi_type' => 'ai_visibility',
        'approval_mode' => 'manual',
        'status' => 'active',
    ], $attributes));
}

function makeActionPlannerContent(Workspace $workspace, array $attributes = []): Content
{
    return Content::query()->create(array_merge([
        'workspace_id' => $workspace->id,
        'title' => 'Agentic marketing guide',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'language' => 'en',
        'publish_status' => 'published',
    ], $attributes));
}

function makeActionPlannerOpportunity(AgenticMarketingObjective $objective, array $attributes = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_merge([
        'objective_id' => $objective->id,
        'title' => 'Improve AI visibility',
        'type' => AgenticMarketingOpportunityType::AiVisibility->value,
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'signals' => [
                'ai_visibility_score' => 35,
            ],
            'score_explanation' => [
                'summary' => 'AI visibility is below the target threshold.',
            ],
        ],
    ], $attributes));
}

it('creates proposed actions with cost risk approval and prerequisite metadata', function () {
    [$org, $workspace] = makeActionPlannerTenant();
    $objective = makeActionPlannerObjective($org, $workspace);
    $content = makeActionPlannerContent($workspace);

    $refresh = makeActionPlannerOpportunity($objective, [
        'content_id' => $content->id,
        'title' => 'Refresh decaying guide',
        'type' => AgenticMarketingOpportunityType::Refresh->value,
        'payload' => [
            'content_id' => $content->id,
            'signals' => [
                'decay_risk_level' => 'high',
                'lifecycle_stage' => 'refresh_needed',
                'freshness_score' => 30,
            ],
            'score_explanation' => [
                'summary' => 'Refresh opportunity from lifecycle signals.',
            ],
        ],
    ]);

    $localization = makeActionPlannerOpportunity($objective, [
        'content_id' => $content->id,
        'title' => 'Add missing locales',
        'type' => AgenticMarketingOpportunityType::LocaleExpansion->value,
        'payload' => [
            'content_id' => $content->id,
            'signals' => [
                'missing_locales' => ['nl', 'de'],
            ],
            'score_explanation' => [
                'summary' => 'Locale variants are missing.',
            ],
        ],
    ]);

    $planner = app(AgenticMarketingActionPlanner::class);

    $refreshResult = $planner->planForOpportunity($refresh);
    $localizationResult = $planner->planForOpportunity($localization);

    expect($refreshResult['created'])->toBe(1)
        ->and($localizationResult['created'])->toBe(2)
        ->and(AgenticMarketingAction::query()->count())->toBe(3);

    $refreshAction = AgenticMarketingAction::query()->where('action_type', 'refresh_article')->firstOrFail();

    expect($refreshAction->status)->toBe(AgenticMarketingAction::STATUS_PROPOSED)
        ->and($refreshAction->estimated_credits)->toBeGreaterThan(0)
        ->and(data_get($refreshAction->payload, 'planning.risk_level'))->toBe('medium')
        ->and(data_get($refreshAction->payload, 'planning.approval_required'))->toBeTrue()
        ->and(data_get($refreshAction->payload, 'planning.prerequisites.met'))->toBeTrue();

    expect(AgenticMarketingAction::query()->where('action_type', 'create_locale_variant')->pluck('payload')->map(fn ($payload) => data_get($payload, 'target_locale'))->sort()->values()->all())
        ->toBe(['de', 'nl']);
});

it('dedupes planned actions and keeps existing open proposals reusable', function () {
    [$org, $workspace] = makeActionPlannerTenant('am-planner-dedupe');
    $objective = makeActionPlannerObjective($org, $workspace, ['approval_mode' => 'policy_engine']);
    $content = makeActionPlannerContent($workspace);
    $opportunity = makeActionPlannerOpportunity($objective, [
        'content_id' => $content->id,
        'type' => AgenticMarketingOpportunityType::InternalLinks->value,
        'payload' => [
            'content_id' => $content->id,
            'signals' => [
                'suggested_link_count' => 2,
                'link_opportunities' => [
                    ['target_content_id' => 'target-one', 'anchor_text_suggestion' => 'guide'],
                ],
            ],
        ],
    ]);

    $planner = app(AgenticMarketingActionPlanner::class);

    $first = $planner->planForOpportunity($opportunity);
    $second = $planner->planForOpportunity($opportunity);

    expect($first['created'])->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and($second['reused'])->toBe(1)
        ->and(AgenticMarketingAction::query()->count())->toBe(1);

    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($action->status)->toBe(AgenticMarketingAction::STATUS_PROPOSED)
        ->and(data_get($action->payload, 'planning.approval_required'))->toBeFalse()
        ->and(data_get($action->payload, 'planning.risk_level'))->toBe('low');
});

it('keeps schema technical improvements at two credits', function () {
    [$org, $workspace] = makeActionPlannerTenant('am-planner-schema-credits');
    $objective = makeActionPlannerObjective($org, $workspace);
    $content = makeActionPlannerContent($workspace, ['schema_type' => null]);
    $opportunity = makeActionPlannerOpportunity($objective, [
        'content_id' => $content->id,
        'title' => 'Resolve SEO indexability signals',
        'type' => AgenticMarketingOpportunityType::SeoIndexability->value,
        'payload' => [
            'content_id' => $content->id,
            'signals' => [
                'issues' => ['missing_schema_type', 'crawled_not_indexed'],
                'schema_type' => '',
            ],
            'score_explanation' => [
                'summary' => 'Recommended because SEO/indexability issue signals reduce discoverability.',
            ],
        ],
    ]);

    app(AgenticMarketingActionPlanner::class)->planForOpportunity($opportunity);

    $action = AgenticMarketingAction::query()->where('action_type', 'add_schema')->firstOrFail();

    expect($action->estimated_credits)->toBe(2)
        ->and(data_get($action->payload, 'planning.estimated_credits'))->toBe(2)
        ->and(data_get($action->payload, 'proposal_details.items.5.signals'))->toContain('estimated_credits: 2');
});

it('records structured proposal details before execution', function () {
    [$org, $workspace] = makeActionPlannerTenant('am-planner-proposal-details');
    $objective = makeActionPlannerObjective($org, $workspace, [
        'audience' => 'marketing managers',
        'competitors' => ['HubSpot', 'Semrush'],
    ]);
    $content = makeActionPlannerContent($workspace, ['title' => 'GEO strategy guide']);
    makeActionPlannerContent($workspace, ['title' => 'AI visibility checklist']);

    $opportunity = makeActionPlannerOpportunity($objective, [
        'content_id' => $content->id,
        'type' => AgenticMarketingOpportunityType::AiVisibility->value,
        'priority_score' => 88,
        'payload' => [
            'content_id' => $content->id,
            'topic' => 'Generative Engine Optimization',
            'related_entities' => ['GEO', 'AI visibility', 'Answer Engine Optimization'],
            'signals' => [
                'questions' => ['What is GEO and why does it matter?'],
                'gap_type' => 'answer_readiness',
                'link_opportunities' => [
                    [
                        'target_title' => 'AI visibility checklist',
                        'anchor_text_suggestion' => 'AI visibility checklist',
                        'reason' => 'Support the answer with an implementation checklist.',
                    ],
                ],
            ],
            'score_explanation' => [
                'summary' => 'The article is missing answer-ready content for AI generated answers.',
            ],
        ],
    ]);

    app(AgenticMarketingActionPlanner::class)->planForOpportunity($opportunity);

    $action = AgenticMarketingAction::query()->where('action_type', 'add_answer_block')->firstOrFail();
    $details = data_get($action->payload, 'proposal_details');

    expect($action->estimated_credits)->toBe(2)
        ->and(data_get($action->payload, 'planning.estimated_credits'))->toBe(2)
        ->and(data_get($details, 'schema'))->toBe('agentic_marketing.action_proposal_details.v1')
        ->and(data_get($details, 'estimated_impact'))->toBe('High')
        ->and(collect(data_get($details, 'items'))->pluck('type')->all())->toContain(
            'generated_answer_block',
            'generated_schema',
            'generated_cta',
            'suggested_links',
            'semantic_entities',
            'visibility_reasoning',
            'estimated_impact',
        )
        ->and(data_get($details, 'items.0.question'))->toBe('What is GEO and why does it matter?')
        ->and(data_get($details, 'items.1.schema.@type'))->toBe('FAQPage')
        ->and(data_get($details, 'items.3.links.0.target'))->toBe('AI visibility checklist')
        ->and(data_get($details, 'items.4.entities'))->toContain('Generative Engine Optimization', 'GEO', 'HubSpot')
        ->and(data_get($details, 'items.5.signals'))->toContain('estimated_credits: 2')
        ->and(data_get($details, 'items.5.reason'))->toContain('answer-ready content');
});

it('skips action creation when prerequisites are not met', function () {
    [$org, $workspace] = makeActionPlannerTenant('am-planner-prereq');
    $objective = makeActionPlannerObjective($org, $workspace);
    $opportunity = makeActionPlannerOpportunity($objective, [
        'content_id' => null,
        'type' => AgenticMarketingOpportunityType::Refresh->value,
        'payload' => [
            'signals' => [
                'lifecycle_stage' => 'refresh_needed',
            ],
        ],
    ]);

    $result = app(AgenticMarketingActionPlanner::class)->planForOpportunity($opportunity);

    expect($result['created'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and(AgenticMarketingAction::query()->count())->toBe(0);
});
