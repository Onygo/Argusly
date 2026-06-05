<?php

use App\Models\ClientSite;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders automation run history with item failures', function () {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
    [$user, $automation] = makeAutomationHistoryContext();

    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => $automation->client_site_id,
        'status' => 'failed',
        'triggered_by' => 'manual',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'result_summary' => 'One item failed.',
        'error_message' => 'Original automation failure',
    ]);

    ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'chain_index' => 1,
        'status' => 'failed',
        'failure_stage' => 'persistence',
        'last_error_code' => 'sql_exception',
        'last_error_message' => 'SQLSTATE[01000]: Warning: 1265 Data truncated for column source',
        'locale' => 'en',
        'title' => 'Failed generated article',
    ]);

    $this->actingAs($user)
        ->get(route('app.content.automations.show', $automation))
        ->assertOk()
        // Now shows user-friendly error presentation instead of raw SQL
        ->assertSee('PL-CNT-SRC-001')
        ->assertSee('Content source data exceeded storage limits');
});

it('renders automation run history when item data is malformed or only present in metadata', function () {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
    [$user, $automation] = makeAutomationHistoryContext();

    ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => $automation->client_site_id,
        'status' => 'failed',
        'triggered_by' => 'scheduled',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'result_summary' => 'Metadata item failed.',
        'error_message' => 'Original generation exception',
        'metadata' => [
            'items' => [
                [
                    'status' => 'failed',
                    'stage' => 'generation',
                    'target_locale' => 'nl',
                    'error' => 'Provider returned malformed title payload',
                ],
                'not-an-array',
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('app.content.automations.show', $automation))
        ->assertOk()
        // Now shows user-friendly error presentation
        ->assertSee('PL-GEN-ERR-001')
        ->assertSee('Content generation failed')
        ->assertDontSee('Undefined variable $item');
});

function makeAutomationHistoryContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Automation History Org',
        'slug' => 'automation-history-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Automation History Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Automation History Site',
        'site_url' => 'https://automation-history.example.com',
        'base_url' => 'https://automation-history.example.com',
        'allowed_domains' => ['automation-history.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $automation = ContentAutomation::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'name' => 'History automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'chain_size' => 2,
        'locale' => 'en',
        'locales' => ['en', 'nl'],
        'topic_scope' => 'History rendering checks',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return [$user, $automation];
}
