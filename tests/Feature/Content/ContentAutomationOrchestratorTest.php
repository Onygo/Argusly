<?php

use App\Enums\ContentAutomationTriggerType;
use App\Jobs\ContentAutomation\RunContentAutomationJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContentAutomation\ContentAutomationArticleService;
use App\Services\ContentAutomation\ContentAutomationOrchestrator;
use App\Services\ContentAutomation\ContentAutomationPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('completes a scheduled automation run and advances the next run timestamp', function () {
    [$user, $automation] = makeContentAutomationOrchestratorContext();

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'AI onboarding chain',
        'chain_theme' => 'AI onboarding',
        'source_locale' => 'en',
        'locales' => ['en'],
        'articles' => [
            ['sequence' => 1, 'title' => 'AI onboarding basics', 'target_locale' => 'en'],
            ['sequence' => 2, 'title' => 'AI onboarding workflow', 'target_locale' => 'en'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->twice()->andReturnUsing(function (ContentAutomation $automation, $run, array $plan) {
        $content = createAutomationRunContent($automation, (string) $run->id, (string) $plan['title']);

        return [
            'draft_id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'published_content_ids' => ((int) $plan['sequence']) === 2 ? [(string) $content->id] : [],
        ];
    });
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $beforeNextRunAt = $automation->next_run_at;

    $run = app(ContentAutomationOrchestrator::class)->run(
        $automation,
        ContentAutomationTriggerType::SCHEDULED,
        $user->id,
    );

    $createdIds = Content::query()
        ->where('automation_run_id', (string) $run->id)
        ->orderBy('created_at')
        ->pluck('id')
        ->map(fn ($id): string => (string) $id)
        ->all();

    expect($run->status->value)->toBe('completed')
        ->and($run->result_summary)->toContain('2 article(s) generated')
        ->and($run->published_content_ids)->toBe([$createdIds[1]])
        ->and($run->generated_content_ids)->toBe($createdIds);

    $automation->refresh();

    expect($automation->last_run_at)->not->toBeNull()
        ->and((int) $automation->run_count)->toBe(1)
        ->and($automation->next_run_at)->not->toBeNull()
        ->and($automation->next_run_at->gt($beforeNextRunAt))->toBeTrue();
});

it('marks the run as partial when one planned article fails', function () {
    [$user, $automation] = makeContentAutomationOrchestratorContext();

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'Revenue operations chain',
        'chain_theme' => 'Revenue operations',
        'source_locale' => 'en',
        'locales' => ['en'],
        'articles' => [
            ['sequence' => 1, 'title' => 'Revenue operations basics', 'target_locale' => 'en'],
            ['sequence' => 2, 'title' => 'Revenue operations checklist', 'target_locale' => 'en'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->once()->ordered()->andReturnUsing(function (ContentAutomation $automation, $run, array $plan) {
        $content = createAutomationRunContent($automation, (string) $run->id, (string) $plan['title']);

        return [
            'draft_id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'published_content_ids' => [],
        ];
    });
    $articleService->shouldReceive('execute')->once()->ordered()->andThrow(new RuntimeException('Generation failed.'));
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run(
        $automation,
        ContentAutomationTriggerType::MANUAL,
        $user->id,
    );

    $createdId = (string) Content::query()->where('automation_run_id', (string) $run->id)->value('id');

    expect($run->status->value)->toBe('partial')
        ->and($run->generated_content_ids)->toBe([$createdId])
        ->and($run->result_summary)->toContain('1 failed');

    expect(data_get($run->metadata, 'items.1.status'))->toBe('failed')
        ->and(data_get($run->metadata, 'items.1.error'))->toBe('Generation failed.');
});

it('marks all failed planned items as failed with persisted error diagnostics', function () {
    Log::spy();
    [$user, $automation] = makeContentAutomationOrchestratorContext();

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'Failure chain',
        'chain_theme' => 'Failure',
        'source_locale' => 'en',
        'locales' => ['en'],
        'articles' => [
            ['sequence' => 1, 'title' => 'Fail one', 'target_locale' => 'en'],
            ['sequence' => 2, 'title' => 'Fail two', 'target_locale' => 'en'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->twice()->andThrow(new RuntimeException('Provider unavailable.'));
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run($automation, ContentAutomationTriggerType::MANUAL, $user->id);

    expect($run->status->value)->toBe('failed')
        ->and($run->generated_content_ids)->toBe([])
        ->and($run->items()->where('status', 'failed')->count())->toBe(2)
        ->and($run->items()->where('last_error_message', 'Provider unavailable.')->count())->toBe(2)
        ->and(data_get($run->metadata, 'last_failure_stage'))->toBe('generation');

    Log::shouldHaveReceived('error')
        ->with('content_automation.item_failed', \Mockery::on(fn (array $context): bool => ($context['exception_message'] ?? '') === 'Provider unavailable.'))
        ->atLeast()
        ->once();
});

it('keeps generated count tied to actual persisted content records only', function () {
    [$user, $automation] = makeContentAutomationOrchestratorContext();

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'Phantom chain',
        'chain_theme' => 'Phantom',
        'source_locale' => 'en',
        'locales' => ['en'],
        'articles' => [
            ['sequence' => 1, 'title' => 'Phantom article', 'target_locale' => 'en'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->once()->andReturn([
        'draft_id' => (string) Str::uuid(),
        'content_id' => 'phantom-content',
        'published_content_ids' => [],
    ]);
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run($automation, ContentAutomationTriggerType::SCHEDULED, $user->id);

    expect($run->status->value)->toBe('failed')
        ->and($run->generated_content_ids)->toBe([])
        ->and(data_get($run->metadata, 'truth.generated_count'))->toBe(0);
});

it('marks publish failures after content persistence as partial and keeps created content', function () {
    [$user, $automation] = makeContentAutomationOrchestratorContext();

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'Publish failure chain',
        'chain_theme' => 'Publish failure',
        'source_locale' => 'en',
        'locales' => ['en'],
        'articles' => [
            ['sequence' => 1, 'title' => 'Publish failure article', 'target_locale' => 'en'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->once()->andReturnUsing(function (ContentAutomation $automation, $run, array $plan) {
        $content = createAutomationRunContent($automation, (string) $run->id, (string) $plan['title']);

        return [
            'draft_id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'published_content_ids' => [],
            'item_status' => ContentAutomationRunItem::STATUS_PARTIAL,
            'failure_stage' => 'publish',
            'last_error_code' => 'publish_exception',
            'last_error_message' => 'Publication queue failed.',
        ];
    });
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run($automation, ContentAutomationTriggerType::MANUAL, $user->id);

    expect($run->status->value)->toBe('partial')
        ->and($run->generated_content_ids)->toHaveCount(1)
        ->and($run->items()->first()->failure_stage)->toBe('publish')
        ->and($run->items()->first()->last_error_message)->toBe('Publication queue failed.');
});

it('repair command recalculates stale counters and status from database truth', function () {
    [, $automation] = makeContentAutomationOrchestratorContext();
    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'completed',
        'triggered_by' => 'manual',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'generated_content_ids' => ['phantom-content'],
        'generated_draft_ids' => [],
        'published_content_ids' => [],
        'metadata' => [],
    ]);
    ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'chain_index' => 1,
        'status' => 'failed',
        'failure_stage' => 'generation',
        'last_error_code' => 'provider_error',
        'last_error_message' => 'Provider failed.',
        'client_site_id' => (string) $automation->client_site_id,
        'locale' => 'en',
    ]);

    $this->artisan('automations:repair-run-state', ['--run-id' => (string) $run->id])
        ->assertSuccessful();

    $run->refresh();
    expect($run->status->value)->toBe('failed')
        ->and($run->generated_content_ids)->toBe([])
        ->and($run->error_message)->toBe('Provider failed.');
});

it('skips execution when max runs has already been reached', function () {
    [$user, $automation] = makeContentAutomationOrchestratorContext([
        'max_runs' => 1,
        'run_count' => 1,
    ]);

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldNotReceive('plan');
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldNotReceive('execute');
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run(
        $automation,
        ContentAutomationTriggerType::SCHEDULED,
        $user->id,
    );

    expect($run->status->value)->toBe('skipped')
        ->and(data_get($run->metadata, 'skip_reason'))->toBe('max_runs_reached');

    expect($automation->fresh()->is_paused)->toBeTrue();
});

it('skips execution when end date has passed', function () {
    [$user, $automation] = makeContentAutomationOrchestratorContext([
        'end_at' => now()->subMinute(),
    ]);

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldNotReceive('plan');
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldNotReceive('execute');
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run(
        $automation,
        ContentAutomationTriggerType::SCHEDULED,
        $user->id,
    );

    expect($run->status->value)->toBe('skipped')
        ->and(data_get($run->metadata, 'skip_reason'))->toBe('end_at_reached');
});

it('stops the remaining lifecycle when the automation is paused during a run', function () {
    [$user, $automation] = makeContentAutomationOrchestratorContext();

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'Lifecycle chain',
        'chain_theme' => 'Lifecycle',
        'source_locale' => 'en',
        'locales' => ['en'],
        'articles' => [
            ['sequence' => 1, 'title' => 'First article', 'target_locale' => 'en'],
            ['sequence' => 2, 'title' => 'Second article', 'target_locale' => 'en'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->once()->andReturnUsing(function (ContentAutomation $automationArg, $run, array $plan) use ($automation) {
        $automation->fresh()->pause();
        $content = createAutomationRunContent($automationArg, (string) $run->id, (string) $plan['title']);

        return [
            'draft_id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'published_content_ids' => [],
        ];
    });
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run(
        $automation,
        ContentAutomationTriggerType::MANUAL,
        $user->id,
    );

    expect($run->status->value)->toBe('partial')
        ->and(data_get($run->metadata, 'stop_reason'))->toBe('paused')
        ->and(count($run->generated_content_ids))->toBe(1);
});

it('skips automation runs cleanly when the site balance is below the minimum credit requirement', function () {
    config()->set('argusly.ai.drafts.credit_cost', 4);
    config()->set('translation.default_credit_cost', 6);

    [$user, $automation] = makeContentAutomationOrchestratorContext([
        'seed_credits' => false,
        'chain_size' => 1,
        'include_translation' => true,
        'locales' => ['en', 'nl'],
    ]);

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldNotReceive('plan');
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldNotReceive('execute');
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run(
        $automation,
        ContentAutomationTriggerType::SCHEDULED,
        $user->id,
    );

    expect($run->status->value)->toBe('failed')
        ->and(data_get($run->metadata, 'failure_pattern'))->toBe('insufficient_credits')
        ->and(data_get($run->metadata, 'failure_code'))->toBe('PL-CREDITS-INSUFFICIENT')
        ->and(data_get($run->metadata, 'failure_details.required_credits'))->toBe(10)
        ->and($run->result_summary)->toContain('Required: 10, available: 0');
});

it('estimates automation credits for chain size four with en and nl correctly', function () {
    config()->set('argusly.ai.drafts.credit_cost', 4);
    config()->set('translation.default_credit_cost', 6);

    [, $automation] = makeContentAutomationOrchestratorContext([
        'chain_size' => 4,
        'include_translation' => true,
        'locales' => ['en', 'nl'],
    ]);

    $evaluation = app(\App\Services\Credits\CreditWarningService::class)->evaluateAutomation($automation);

    expect(data_get($evaluation, 'estimate.source_generation_credits'))->toBe(16)
        ->and(data_get($evaluation, 'estimate.translation_credits'))->toBe(24)
        ->and((int) data_get($evaluation, 'required_credits'))->toBe(40);
});

it('preserves insufficient credit failure details over generic queue exhaustion messages', function () {
    [$user, $automation] = makeContentAutomationOrchestratorContext();

    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'failed',
        'triggered_by' => 'manual',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'error_message' => 'This automation could not continue because there are not enough credits available. Required: 6, available: 3. Please add credits or reduce the automation scope and try again.',
        'metadata' => [
            'failure_pattern' => 'insufficient_credits',
            'failure_code' => 'PL-CREDITS-INSUFFICIENT',
            'failure_details' => [
                'user_safe_message' => 'This automation could not continue because there are not enough credits available. Required: 6, available: 3. Please add credits or reduce the automation scope and try again.',
            ],
        ],
    ]);

    $job = new RunContentAutomationJob((string) $automation->id, 'manual', $user->id);
    $job->failed(new RuntimeException('RunContentAutomationJob has been attempted too many times.'));

    expect($run->fresh()->error_message)
        ->toContain('Job stopped after too many attempts. Original error:')
        ->and($automation->fresh()->last_failure_message)
        ->toContain('Job stopped after too many attempts. Original error:');
});

it('resumes an incomplete run without regenerating completed chain items', function () {
    [$user, $automation] = makeContentAutomationOrchestratorContext();

    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'partial',
        'triggered_by' => 'manual',
        'attempt_count' => 1,
        'last_attempt_at' => now()->subMinutes(10),
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(9),
        'metadata' => [
            'plan' => [
                'source_locale' => 'en',
                'locales' => ['en'],
                'articles' => [
                    ['sequence' => 1, 'title' => 'Recovered item one', 'target_locale' => 'en', 'stable_key' => 'one'],
                    ['sequence' => 2, 'title' => 'Recovered item two', 'target_locale' => 'en', 'stable_key' => 'two'],
                ],
            ],
        ],
    ]);

    $existingContent = createAutomationRunContent($automation, (string) $run->id, 'Recovered item one');

    ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'chain_index' => 1,
        'item_type' => 'source',
        'status' => 'completed',
        'content_id' => (string) $existingContent->id,
        'client_site_id' => (string) $automation->client_site_id,
        'locale' => 'en',
        'source_locale' => 'en',
        'is_source_locale' => true,
        'title' => 'Recovered item one',
    ]);

    $failedItem = ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'chain_index' => 2,
        'item_type' => 'source',
        'status' => 'failed',
        'failure_stage' => 'generation',
        'last_error_code' => 'provider_error',
        'last_error_message' => 'Provider unavailable.',
        'client_site_id' => (string) $automation->client_site_id,
        'locale' => 'en',
        'source_locale' => 'en',
        'is_source_locale' => true,
        'title' => 'Recovered item two',
    ]);

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldNotReceive('plan');
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->once()->andReturnUsing(function (ContentAutomation $automation, $run, array $plan, $actor, $item) {
        expect((string) $item->id)->not->toBe('');
        $content = createAutomationRunContent($automation, (string) $run->id, (string) $plan['title']);

        return [
            'draft_id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'published_content_ids' => [],
        ];
    });
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $result = app(ContentAutomationOrchestrator::class)->run(
        $automation,
        ContentAutomationTriggerType::MANUAL,
        $user->id,
    );

    expect((string) $result->id)->toBe((string) $run->id)
        ->and((int) $result->attempt_count)->toBe(2)
        ->and(ContentAutomationRunItem::query()->where('automation_run_id', (string) $run->id)->count())->toBe(2)
        ->and(Content::query()->where('automation_run_id', (string) $run->id)->count())->toBe(2);

    expect($failedItem->fresh()->status)->toBe('completed');
});

it('diagnose and repair commands surface real failures and repair stale runs', function () {
    [$user, $automation] = makeContentAutomationOrchestratorContext();

    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'running',
        'triggered_by' => 'manual',
        'attempt_count' => 2,
        'last_attempt_at' => now()->subHour(),
        'started_at' => now()->subHour(),
        'metadata' => [
            'real_error' => [
                'message' => 'SQLSTATE[22001]: value too long for title column',
            ],
        ],
    ]);

    ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'chain_index' => 1,
        'item_type' => 'source',
        'status' => 'running',
        'client_site_id' => (string) $automation->client_site_id,
        'locale' => 'en',
        'source_locale' => 'en',
        'is_source_locale' => true,
        'title' => 'Stale running item',
    ]);

    $this->artisan('content-automation:diagnose', [
        'automationId' => (string) $automation->id,
        '--run' => (string) $run->id,
    ])->assertSuccessful()
        ->expectsOutputToContain((string) $automation->id)
        ->expectsOutputToContain('SQLSTATE[22001]: value too long for title column');

    $this->artisan('content-automation:repair-runs', [
        'automationId' => (string) $automation->id,
        '--fix' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('stale_runs_marked');

    expect($run->fresh()->status->value)->toBe('failed')
        ->and($run->fresh()->error_message)->toContain('stale');
});

function makeContentAutomationOrchestratorContext(array $overrides = []): array
{
    $seedCredits = (bool) ($overrides['seed_credits'] ?? true);
    unset($overrides['seed_credits']);

    $organization = Organization::query()->create([
        'name' => 'Automation Orchestrator Org',
        'slug' => 'automation-orchestrator-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Automation Orchestrator BV',
        'billing_address_line1' => 'Teststraat 15',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Automation Orchestrator Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Automation Orchestrator Site',
        'site_url' => 'https://automation-orchestrator.example.com',
        'base_url' => 'https://automation-orchestrator.example.com',
        'allowed_domains' => ['automation-orchestrator.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'automation-orchestrator-plan'],
        [
            'name' => 'Automation Orchestrator Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::query()->create([
        'name' => 'Automation Orchestrator Owner',
        'email' => 'automation-orchestrator-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $automation = ContentAutomation::query()->create(array_merge([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Revenue automation',
        'is_active' => true,
        'is_paused' => false,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->subMinute(),
        'run_count' => 0,
        'chain_size' => 2,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Revenue operations',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ], $overrides));

    if ($seedCredits) {
        app(\App\Services\CreditWalletService::class)->addCredits(
            clientSiteId: (string) $site->id,
            amount: 25,
            type: \App\Services\CreditWalletService::TYPE_ADJUSTMENT,
            meta: ['source' => 'content-automation-orchestrator-test'],
        );
    }

    return [$user, $automation];
}

function createAutomationRunContent(ContentAutomation $automation, string $runId, string $title): Content
{
    return Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'title' => $title,
        'language' => $automation->sourceLocale(),
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'automation',
        'automation_id' => (string) $automation->id,
        'automation_run_id' => $runId,
    ]);
}
