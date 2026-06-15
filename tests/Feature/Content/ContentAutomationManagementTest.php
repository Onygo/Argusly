<?php

use App\Jobs\ContentAutomation\RunContentAutomationJob;
use App\Models\ClientSite;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('lets an owner create, inspect, and control a content automation', function () {
    [$owner, $workspace, $site] = makeContentAutomationManagementContext();

    $this->actingAs($owner)
        ->get(route('app.content.automations.create', ['site' => $site->id]))
        ->assertOk()
        ->assertSee('New content automation')
        ->assertSee('Topic scope');

    $response = $this->actingAs($owner)
        ->post(route('app.content.automations.store'), [
            'name' => 'Autonomous CRM chain',
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'mode' => 'chain',
            'publication_mode' => 'draft_only',
            'generation_frequency_value' => 3,
            'generation_frequency_unit' => 'days',
            'chain_size' => 5,
            'locale' => 'en',
            'locales' => ['en', 'nl'],
            'topic_scope' => 'CRM migration strategy',
            'content_goal' => 'Generate a linked educational chain',
            'end_at' => now()->addWeek()->format('Y-m-d H:i:s'),
            'max_runs' => 10,
            'include_internal_linking' => 1,
            'include_translation' => 1,
            'avoid_topic_overlap' => 1,
            'is_active' => 1,
            'content_pillars' => "CRM migration\nChange management",
        ]);

    $automation = ContentAutomation::query()->firstOrFail();

    $response->assertRedirect(route('app.content.automations.show', $automation));

    expect($automation->chain_size)->toBe(5)
        ->and($automation->include_internal_linking)->toBeTrue()
        ->and($automation->include_translation)->toBeTrue()
        ->and((int) $automation->max_runs)->toBe(10)
        ->and(data_get($automation->settings, 'content_pillars'))->toContain('CRM migration');

    $this->actingAs($owner)
        ->get(route('app.content.automations.show', $automation))
        ->assertOk()
        ->assertSee('Run history')
        ->assertSee('CRM migration strategy');

    Bus::fake();

    $this->actingAs($owner)
        ->post(route('app.content.automations.run', $automation))
        ->assertRedirect()
        ->assertSessionHas('status', 'Automation run queued.');

    Bus::assertDispatched(RunContentAutomationJob::class, fn (RunContentAutomationJob $job): bool => $job->automationId === (string) $automation->id && $job->triggerType === 'manual');

    $this->actingAs($owner)
        ->post(route('app.content.automations.pause', $automation))
        ->assertRedirect();

    expect($automation->fresh()->paused_at)->not->toBeNull();

    $this->actingAs($owner)
        ->post(route('app.content.automations.resume', $automation))
        ->assertRedirect();

    expect($automation->fresh()->paused_at)->toBeNull()
        ->and($automation->fresh()->is_paused)->toBeFalse()
        ->and($automation->fresh()->next_run_at)->not->toBeNull();

    $this->actingAs($owner)
        ->post(route('app.content.automations.duplicate', $automation))
        ->assertRedirect();

    expect(ContentAutomation::query()->count())->toBe(2);

    $this->actingAs($owner)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->assertSee('Automations');
});

it('prevents manual run when the automation is paused and resume schedules a future run', function () {
    [$owner, $workspace, $site] = makeContentAutomationManagementContext();

    $automation = ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Paused chain',
        'is_active' => true,
        'is_paused' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->subMinute(),
        'chain_size' => 5,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Paused scope',
        'paused_at' => now(),
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    Bus::fake();

    $this->actingAs($owner)
        ->post(route('app.content.automations.run', $automation))
        ->assertRedirect()
        ->assertSessionHasErrors('automation');

    Bus::assertNotDispatched(RunContentAutomationJob::class);

    $this->actingAs($owner)
        ->post(route('app.content.automations.resume', $automation))
        ->assertRedirect()
        ->assertSessionHas('status', 'Automation resumed.');

    expect($automation->fresh()->is_paused)->toBeFalse()
        ->and($automation->fresh()->paused_at)->toBeNull()
        ->and($automation->fresh()->next_run_at->isFuture())->toBeTrue();
});

it('forces translation on chained automations when multiple locales are selected', function () {
    [$owner, $workspace, $site] = makeContentAutomationManagementContext();

    $response = $this->actingAs($owner)
        ->post(route('app.content.automations.store'), [
            'name' => 'Localized CRM chain',
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'mode' => 'chain',
            'publication_mode' => 'draft_only',
            'generation_frequency_value' => 3,
            'generation_frequency_unit' => 'days',
            'chain_size' => 3,
            'locale' => 'en',
            'locales' => ['en', 'nl'],
            'topic_scope' => 'CRM migration strategy',
            'include_translation' => 0,
            'is_active' => 1,
        ]);

    $automation = ContentAutomation::query()->firstOrFail();

    $response->assertRedirect(route('app.content.automations.show', $automation));

    expect($automation->include_translation)->toBeTrue()
        ->and($automation->targetLocales())->toBe(['nl']);
});

it('blocks manual run before dispatch when estimated credits are too low', function () {
    config()->set('argusly.ai.drafts.credit_cost', 4);
    config()->set('translation.default_credit_cost', 6);

    [$owner, $workspace, $site] = makeContentAutomationManagementContext();
    $lowCreditSite = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Low Credit Site',
        'site_url' => 'https://low-credit.example.com',
        'base_url' => 'https://low-credit.example.com',
        'allowed_domains' => ['low-credit.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $automation = ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $lowCreditSite->id,
        'name' => 'Low credit chain',
        'is_active' => true,
        'is_paused' => false,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->subMinute(),
        'chain_size' => 1,
        'locale' => 'en',
        'locales' => ['en', 'nl'],
        'include_translation' => true,
        'topic_scope' => 'Low credit scope',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    Bus::fake();

    $this->actingAs($owner)
        ->post(route('app.content.automations.run', $automation))
        ->assertRedirect()
        ->assertSessionHasErrors([
            'automation' => 'This automation needs 10 credits, but only 0 are available.',
        ]);

    Bus::assertNotDispatched(RunContentAutomationJob::class);
});

it('shows recalculated failure diagnostics on automation index and detail pages', function () {
    [$owner, $workspace, $site] = makeContentAutomationManagementContext();

    $automation = ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Diagnostics chain',
        'is_active' => true,
        'is_paused' => false,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->addDay(),
        'chain_size' => 1,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Diagnostics scope',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'failed',
        'triggered_by' => 'manual',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'error_message' => 'Provider failed.',
        'generated_content_ids' => [],
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
        'client_site_id' => (string) $site->id,
        'locale' => 'en',
    ]);

    $this->actingAs($owner)
        ->get(route('app.content.automations.index'))
        ->assertOk()
        ->assertSee('Last failure: Provider failed.')
        ->assertSee('1 run(s), 1 item(s)');

    $this->actingAs($owner)
        ->get(route('app.content.automations.show', $automation))
        ->assertOk()
        // Now shows user-friendly error presentation
        ->assertSee('PL-GEN-ERR-001')
        ->assertSee('Content generation failed')
        ->assertSee('generation');
});

function makeContentAutomationManagementContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Automation Management Org',
        'slug' => 'automation-management-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Automation Management BV',
        'billing_address_line1' => 'Teststraat 42',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Automation Management Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Automation Management Site',
        'site_url' => 'https://automation-management.example.com',
        'base_url' => 'https://automation-management.example.com',
        'allowed_domains' => ['automation-management.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'automation-management-plan'],
        [
            'name' => 'Automation Management Plan',
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

    $owner = User::query()->create([
        'name' => 'Automation Management Owner',
        'email' => 'automation-management-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    app(CreditWalletService::class)->addCredits((string) $site->id, 100, CreditWalletService::TYPE_ALLOWANCE);

    return [$owner, $workspace, $site];
}
