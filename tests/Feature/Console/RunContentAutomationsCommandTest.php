<?php

use App\Jobs\ContentAutomation\RunContentAutomationJob;
use App\Models\ClientSite;
use App\Models\ContentAutomation;
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

it('dispatches only due active content automations', function () {
    [$workspace, $site] = makeContentAutomationSchedulerContext();

    $due = ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Due automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->subMinute(),
        'chain_size' => 5,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Revenue operations',
    ]);

    ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Future automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->addDay(),
        'chain_size' => 5,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Customer onboarding',
    ]);

    ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Paused automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->subMinute(),
        'chain_size' => 5,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Site migration',
        'is_paused' => true,
        'paused_at' => now(),
    ]);

    ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Finished automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->subMinute(),
        'chain_size' => 5,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Legacy migration',
        'max_runs' => 3,
        'run_count' => 3,
    ]);

    Bus::fake();

    $this->artisan('content:run-automations --limit=10')
        ->expectsOutputToContain('Automation skipped: paused')
        ->expectsOutputToContain('Automation skipped: max_runs reached')
        ->expectsOutputToContain('Dispatched 1 content automation run(s).')
        ->assertExitCode(0);

    Bus::assertDispatched(RunContentAutomationJob::class, fn (RunContentAutomationJob $job): bool => $job->automationId === (string) $due->id);
    Bus::assertDispatchedTimes(RunContentAutomationJob::class, 1);
});

it('does not dispatch due automations when estimated credits are insufficient', function () {
    config()->set('publishlayer.ai.drafts.credit_cost', 4);
    config()->set('translation.default_credit_cost', 6);

    [$workspace, $site] = makeContentAutomationSchedulerContext();
    $lowCreditSite = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Scheduler Low Credit Site',
        'site_url' => 'https://scheduler-low-credit.example.com',
        'base_url' => 'https://scheduler-low-credit.example.com',
        'allowed_domains' => ['scheduler-low-credit.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $lowCreditSite->id,
        'name' => 'Low credit automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->subMinute(),
        'chain_size' => 1,
        'locale' => 'en',
        'locales' => ['en', 'nl'],
        'include_translation' => true,
        'topic_scope' => 'Revenue operations',
    ]);

    Bus::fake();

    $this->artisan('content:run-automations --limit=10')
        ->expectsOutputToContain('Automation skipped: insufficient credits')
        ->expectsOutputToContain('No due content automations found.')
        ->assertExitCode(0);

    Bus::assertNotDispatched(RunContentAutomationJob::class);
});

function makeContentAutomationSchedulerContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Automation Command Org',
        'slug' => 'automation-command-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Automation Command BV',
        'billing_address_line1' => 'Teststraat 12',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Automation Command Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Automation Command Site',
        'site_url' => 'https://automation-command.example.com',
        'base_url' => 'https://automation-command.example.com',
        'allowed_domains' => ['automation-command.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'automation-command-plan'],
        [
            'name' => 'Automation Command Plan',
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

    User::query()->create([
        'name' => 'Automation Command Owner',
        'email' => 'automation-command-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    app(CreditWalletService::class)->addCredits((string) $site->id, 100, CreditWalletService::TYPE_ALLOWANCE);

    return [$workspace, $site];
}
