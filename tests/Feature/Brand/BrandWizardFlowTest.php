<?php

use App\Jobs\GenerateBrandContextJob;
use App\Models\EnrichmentRun;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BrandContext\BrandContextService;
use App\Services\WorkspaceIntelligence\AIAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::create([
        'name' => 'Brand Wizard Org',
        'slug' => 'brand-wizard-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Brand Wizard Org BV',
        'billing_address_line1' => 'Wizardstraat 1',
        'billing_country_code' => 'NL',
    ]);

    $this->workspace = Workspace::create([
        'name' => 'Brand Wizard Workspace',
        'organization_id' => $this->organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'brand-wizard-plan'],
        [
            'name' => 'Brand Wizard Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->organization->id,
        'workspace_id' => $this->workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $this->owner = User::create([
        'name' => 'Brand Wizard Owner',
        'email' => 'brand-wizard+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $this->organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->superadmin = User::create([
        'name' => 'Brand Wizard Superadmin',
        'email' => 'brand-superadmin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $this->organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
        'is_admin' => true,
        'admin_role' => 'superadmin',
    ]);
});

it('creates a brand context run, reviews completed sections and applies linked brand records', function () {
    Queue::fake();

    $mock = Mockery::mock(AIAnalysisService::class);
    $mock->shouldReceive('generateBrandContextDetailed')->once()->andReturn(successfulBrandAnalysis());
    $mock->shouldReceive('generateBrandContext')->zeroOrMoreTimes();
    $this->app->instance(AIAnalysisService::class, $mock);

    $response = $this->actingAs($this->owner)->post(route('app.brand.wizard.store'), [
        'input_type' => 'text',
        'pasted_text' => 'Acme Cloud automates revenue operations for SaaS teams.',
        'sections' => EnrichmentRun::BRAND_SECTIONS,
        'generation_mode' => 'full',
    ]);

    $run = EnrichmentRun::query()->firstOrFail();

    $response->assertRedirect(route('app.brand.wizard.review', $run));
    Queue::assertPushed(GenerateBrandContextJob::class);

    app(BrandContextService::class)->generateBrandContext($run->fresh());
    $run->refresh();

    expect($run->status)->toBe(EnrichmentRun::STATUS_COMPLETED);

    $this->assertDatabaseHas('brand_contexts', [
        'workspace_id' => $this->workspace->id,
        'source_type' => 'text',
    ]);

    $this->actingAs($this->owner)
        ->get(route('app.brand.wizard.review', $run))
        ->assertOk()
        ->assertSee('Apply selected sections')
        ->assertSee('Operations Manager Olivia');

    $this->actingAs($this->owner)
        ->post(route('app.brand.wizard.apply', $run), [
            'sections' => EnrichmentRun::BRAND_SECTIONS,
        ])
        ->assertRedirect(route('app.brand.voices'));

    $run->refresh();

    expect($run->status)->toBe(EnrichmentRun::STATUS_APPROVED);

    $brandContextId = data_get($run->extracted_payload, 'brand_context_id');

    $this->assertDatabaseHas('company_profiles', [
        'workspace_id' => $this->workspace->id,
        'company_name' => 'Acme Cloud',
        'generated_from_context_id' => $brandContextId,
    ]);

    $this->assertDatabaseHas('brand_voices', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Professional & Authoritative',
        'generated_from_context_id' => $brandContextId,
    ]);

    $this->assertDatabaseHas('personas', [
        'organization_id' => $this->organization->id,
        'name' => 'Operations Manager Olivia',
        'generated_from_context_id' => $brandContextId,
    ]);

    $this->assertDatabaseHas('team_members', [
        'organization_id' => $this->organization->id,
        'name' => 'Founder',
        'generated_from_context_id' => $brandContextId,
    ]);
});

it('renders the animated cooking ui for queued and processing runs', function () {
    $run = makeBrandRun($this->organization, [
        'status' => EnrichmentRun::STATUS_PROCESSING,
        'progress' => 0.4,
        'started_at' => now()->subMinute(),
        'queued_at' => now()->subMinutes(2),
    ]);

    $this->actingAs($this->owner)
        ->get(route('app.brand.wizard.review', $run))
        ->assertOk()
        ->assertSee('AI is preparing your brand setup')
        ->assertSee('We are reading the source, extracting brand context and preparing editable sections.')
        ->assertDontSee('Apply selected sections');
});

it('returns monotonic stored progress from the status endpoint', function () {
    $run = makeBrandRun($this->organization, [
        'status' => EnrichmentRun::STATUS_PROCESSING,
        'progress' => 0.4,
        'queued_at' => now()->subMinutes(2),
        'started_at' => now()->subMinute(),
    ]);

    $service = app(BrandContextService::class);
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('syncRunState');
    $method->setAccessible(true);
    $method->invoke($service, $run, ['progress' => 0.1, 'last_heartbeat_at' => now()]);

    $this->actingAs($this->owner)
        ->getJson(route('app.brand.wizard.status', $run))
        ->assertOk()
        ->assertJsonPath('progress', 0.4)
        ->assertJsonPath('status', EnrichmentRun::STATUS_PROCESSING);
});

it('renders completed empty recovery state and hides apply actions', function () {
    $run = makeBrandRun($this->organization, [
        'status' => EnrichmentRun::STATUS_COMPLETED_EMPTY,
        'progress' => 1,
        'error_message' => 'The AI run finished, but did not return usable brand context.',
        'failure_reason' => 'no_sections_generated',
        'completed_at' => now(),
        'diagnostic_payload' => [
            'run_id' => 'diag-run',
            'provider' => 'openai',
            'model' => 'gpt-test',
            'sections_count' => 0,
        ],
    ]);

    $this->actingAs($this->owner)
        ->get(route('app.brand.wizard.review', $run))
        ->assertOk()
        ->assertSee('No brand sections were generated')
        ->assertSee('Retry generation')
        ->assertDontSee('Apply selected sections')
        ->assertDontSee('Generated context');
});

it('renders failed recovery state and limits diagnostics to admin users', function () {
    $run = makeBrandRun($this->organization, [
        'status' => EnrichmentRun::STATUS_FAILED,
        'progress' => 1,
        'error_message' => 'The AI provider timed out.',
        'failure_reason' => 'generation_exception',
        'failed_at' => now(),
        'diagnostic_payload' => [
            'run_id' => 'diag-run',
            'provider' => 'openai',
            'model' => 'gpt-test',
            'raw_response_length' => 0,
        ],
    ]);

    $this->actingAs($this->owner)
        ->get(route('app.brand.wizard.review', $run))
        ->assertOk()
        ->assertSee('Generation failed')
        ->assertSee('Retry generation')
        ->assertDontSee('View technical details');

    $this->actingAs($this->superadmin)
        ->withSession([
            'support_mode_enabled' => true,
            'support_target_company_id' => $this->organization->id,
            'support_target_user_id' => $this->owner->id,
            'support_started_by_admin_id' => $this->superadmin->id,
            'support_started_at' => now()->toIso8601String(),
            'support_reason' => 'Brand wizard diagnostics test',
        ])
        ->get(route('app.brand.wizard.review', $run))
        ->assertOk()
        ->assertSee('View technical details')
        ->assertSee('gpt-test');
});

it('rejects applying empty or unavailable sections', function () {
    $run = makeBrandRun($this->organization, [
        'status' => EnrichmentRun::STATUS_COMPLETED_EMPTY,
        'progress' => 1,
        'ai_payload' => [],
        'completed_at' => now(),
    ]);

    $this->actingAs($this->owner)
        ->post(route('app.brand.wizard.apply', $run), [
            'sections' => [],
        ])
        ->assertSessionHasErrors();

    $run = makeBrandRun($this->organization, [
        'status' => EnrichmentRun::STATUS_COMPLETED,
        'progress' => 1,
        'ai_payload' => [
            'company_profile' => successfulBrandPayload()['company_profile'],
        ],
        'completed_at' => now(),
    ]);

    $this->actingAs($this->owner)
        ->post(route('app.brand.wizard.apply', $run), [
            'sections' => ['brand_voices'],
        ])
        ->assertSessionHasErrors(['wizard']);
});

it('marks the run completed empty when parsed sections are empty', function () {
    $mock = Mockery::mock(AIAnalysisService::class);
    $mock->shouldReceive('generateBrandContextDetailed')->once()->andReturn([
        'payload' => [],
        'provider' => 'openai',
        'model' => 'gpt-test',
        'request_id' => 'req-empty',
        'raw_response_length' => 12,
        'parser_error' => null,
    ]);
    $mock->shouldReceive('generateBrandContext')->zeroOrMoreTimes();
    $this->app->instance(AIAnalysisService::class, $mock);

    $run = makeBrandRun($this->organization, [
        'status' => EnrichmentRun::STATUS_QUEUED,
        'progress' => 0,
        'queued_at' => now()->subMinute(),
    ]);

    app(BrandContextService::class)->generateBrandContext($run);

    $run->refresh();

    expect($run->status)->toBe(EnrichmentRun::STATUS_COMPLETED_EMPTY)
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->failure_reason)->toBe('no_sections_generated');
});

it('retries safely by creating a new run and reuses active runs', function () {
    Queue::fake();

    $failedRun = makeBrandRun($this->organization, [
        'status' => EnrichmentRun::STATUS_FAILED,
        'progress' => 1,
        'failed_at' => now(),
        'failure_reason' => 'generation_exception',
    ]);

    $this->actingAs($this->owner)
        ->post(route('app.brand.wizard.retry', $failedRun))
        ->assertRedirect();

    Queue::assertPushed(GenerateBrandContextJob::class);

    $newRun = EnrichmentRun::query()
        ->where('organization_id', $this->organization->id)
        ->where('id', '!=', $failedRun->id)
        ->latest('created_at')
        ->firstOrFail();

    expect((string) $newRun->id)->not->toBe((string) $failedRun->id)
        ->and($newRun->status)->toBe(EnrichmentRun::STATUS_QUEUED);

    Queue::fake();

    $activeRun = makeBrandRun($this->organization, [
        'status' => EnrichmentRun::STATUS_PROCESSING,
        'progress' => 0.4,
        'queued_at' => now()->subMinutes(2),
        'started_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->owner)
        ->post(route('app.brand.wizard.retry', $failedRun))
        ->assertRedirect(route('app.brand.wizard.review', $activeRun));

    Queue::assertNothingPushed();
});

function makeBrandRun(Organization $organization, array $overrides = []): EnrichmentRun
{
    return EnrichmentRun::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'enrichable_type' => EnrichmentRun::ENRICHABLE_ORGANIZATION,
        'enrichment_type' => EnrichmentRun::TYPE_BRAND_CONTEXT,
        'source_type' => 'text',
        'source_payload' => ['input_text' => 'Acme source text'],
        'requested_sections' => EnrichmentRun::BRAND_SECTIONS,
        'generation_mode' => EnrichmentRun::GENERATION_MODE_FULL,
        'status' => EnrichmentRun::STATUS_QUEUED,
        'progress' => 0,
        'queued_at' => now(),
        'last_heartbeat_at' => now(),
    ], $overrides));
}

function successfulBrandAnalysis(): array
{
    return [
        'payload' => successfulBrandPayload(),
        'provider' => 'openai',
        'model' => 'gpt-test',
        'request_id' => 'req-success',
        'raw_response_length' => 512,
        'parser_error' => null,
    ];
}

function successfulBrandPayload(): array
{
    return [
        'company_profile' => [
            'company_name' => 'Acme Cloud',
            'industry' => 'B2B SaaS',
            'short_description' => 'Acme helps ops teams automate repetitive work.',
            'long_description' => 'Acme Cloud provides workflow automation for revenue and operations teams.',
            'value_proposition' => 'Reduce manual ops work without fragile spreadsheets.',
            'key_services' => ['Workflow automation', 'Revenue operations'],
            'value_propositions' => ['Faster ops execution', 'Cleaner reporting'],
            'proof_points' => ['200 customers', 'SOC 2 compliant'],
            'target_audience' => 'Operations leaders at scaling SaaS companies',
            'mission' => 'Remove operational drag for growth teams.',
            'vision' => 'Every modern revenue team runs on reliable automation.',
        ],
        'brand_voices' => [
            [
                'name' => 'Professional & Authoritative',
                'tone_of_voice' => 'Confident and strategic',
                'writing_style' => 'Clear, concise and evidence-driven',
                'do_rules' => ['Lead with outcomes', 'Use specific examples'],
                'dont_rules' => ['Do not overpromise'],
                'description' => 'Thought leadership voice',
                'example_paragraph' => 'Strong operators need systems they can trust.',
            ],
        ],
        'buyer_personas' => [
            [
                'type' => 'buyer',
                'name' => 'Operations Manager Olivia',
                'role' => 'Operations Manager',
                'summary' => 'Owns process design and tooling decisions.',
                'goals' => ['Improve process speed'],
                'pain_points' => ['Too much spreadsheet work'],
                'buying_triggers' => ['Headcount pressure'],
                'objections' => ['Implementation risk'],
                'content_preferences' => ['Frameworks', 'Benchmarks'],
            ],
        ],
        'team_personas' => [
            [
                'name' => 'Founder',
                'title' => 'Founder',
                'role' => 'Founder',
                'writing_perspective' => 'Write from hands-on company-building experience.',
                'expertise_areas' => ['Revenue operations', 'Automation'],
                'tone_traits' => ['Direct', 'Practical'],
                'use_as_writing_persona' => true,
                'link_to_real_team_member_later' => true,
            ],
        ],
    ];
}
