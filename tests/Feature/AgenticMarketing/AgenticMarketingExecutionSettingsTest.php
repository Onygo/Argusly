<?php

use App\Models\AgenticMarketingExecutionSetting;
use App\Models\ClientSite;
use App\Models\ContentDestination;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('defaults Agentic Marketing execution to guided mode', function () {
    [$user, $workspace] = makeAgenticExecutionSettingsContext();

    $this->actingAs($user)
        ->get(route('app.settings'))
        ->assertOk()
        ->assertSee('Agentic Marketing Execution')
        ->assertSee('Guided')
        ->assertSee('Autonomous mode is never enabled by default.');

    $default = AgenticMarketingExecutionSetting::defaultsFor($workspace);

    expect($default->agentic_execution_mode)->toBe(AgenticMarketingExecutionSetting::MODE_GUIDED)
        ->and($default->autonomous_publication_enabled)->toBeFalse()
        ->and(AgenticMarketingExecutionSetting::query()->count())->toBe(0);
});

it('allows a workspace owner to explicitly enable autonomous mode with bounded rules', function () {
    [$user, $workspace, , $site] = makeAgenticExecutionSettingsContext();
    $destination = ContentDestination::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Primary destination',
        'type' => 'wordpress',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->from(route('app.settings'))
        ->post(route('app.settings.agentic-marketing-execution.update'), [
            'agentic_execution_mode' => 'autonomous',
            'autonomous_opt_in_confirmation' => '1',
            'autonomous_refresh_enabled' => '1',
            'autonomous_internal_linking_enabled' => '1',
            'max_autonomous_actions_per_day' => 6,
            'max_autonomous_credits_per_month' => 250,
            'require_approval_above_priority_score' => 70,
            'require_approval_for_new_pages' => '1',
            'require_approval_for_external_publication' => '1',
            'allowed_site_ids' => [(string) $site->id],
            'allowed_publishing_destination_ids' => [(string) $destination->id],
            'notification_email_enabled' => '1',
        ])
        ->assertRedirect(route('app.settings'))
        ->assertSessionHasNoErrors();

    $settings = AgenticMarketingExecutionSetting::query()
        ->where('workspace_id', $workspace->id)
        ->firstOrFail();

    expect($settings->agentic_execution_mode)->toBe('autonomous')
        ->and($settings->autonomous_refresh_enabled)->toBeTrue()
        ->and($settings->autonomous_internal_linking_enabled)->toBeTrue()
        ->and($settings->max_autonomous_actions_per_day)->toBe(6)
        ->and($settings->max_autonomous_credits_per_month)->toBe(250)
        ->and($settings->require_approval_above_priority_score)->toBe(70)
        ->and($settings->allowed_site_ids)->toBe([(string) $site->id])
        ->and($settings->allowed_publishing_destination_ids)->toBe([(string) $destination->id]);
});

it('rejects invalid autonomous settings server side', function () {
    [$user] = makeAgenticExecutionSettingsContext();

    $this->actingAs($user)
        ->from(route('app.settings'))
        ->post(route('app.settings.agentic-marketing-execution.update'), [
            'agentic_execution_mode' => 'autonomous',
            'autonomous_opt_in_confirmation' => '1',
            'autonomous_refresh_enabled' => '1',
            'max_autonomous_actions_per_day' => 0,
            'max_autonomous_credits_per_month' => 0,
            'require_approval_above_priority_score' => 101,
            'allowed_site_ids' => [],
        ])
        ->assertRedirect(route('app.settings'))
        ->assertSessionHasErrors([
            'max_autonomous_actions_per_day',
            'max_autonomous_credits_per_month',
            'require_approval_above_priority_score',
        ]);

    expect(AgenticMarketingExecutionSetting::query()->count())->toBe(0);

    $this->actingAs($user)
        ->from(route('app.settings'))
        ->post(route('app.settings.agentic-marketing-execution.update'), [
            'agentic_execution_mode' => 'autonomous',
            'autonomous_opt_in_confirmation' => '1',
            'autonomous_refresh_enabled' => '1',
            'max_autonomous_actions_per_day' => 3,
            'max_autonomous_credits_per_month' => 100,
            'require_approval_above_priority_score' => 80,
            'allowed_site_ids' => [],
        ])
        ->assertRedirect(route('app.settings'))
        ->assertSessionHasErrors(['allowed_site_ids']);
});

it('rejects publishing sites and destinations outside the current workspace', function () {
    [$user, $workspace, , $site] = makeAgenticExecutionSettingsContext('agentic-tenant-a');
    [, $otherWorkspace, , $otherSite] = makeAgenticExecutionSettingsContext('agentic-tenant-b');
    $otherDestination = ContentDestination::query()->create([
        'workspace_id' => $otherWorkspace->id,
        'name' => 'Other destination',
        'type' => 'wordpress',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->from(route('app.settings'))
        ->post(route('app.settings.agentic-marketing-execution.update'), [
            'agentic_execution_mode' => 'autonomous',
            'autonomous_opt_in_confirmation' => '1',
            'autonomous_refresh_enabled' => '1',
            'max_autonomous_actions_per_day' => 3,
            'max_autonomous_credits_per_month' => 100,
            'require_approval_above_priority_score' => 80,
            'allowed_site_ids' => [(string) $site->id, (string) $otherSite->id],
            'allowed_publishing_destination_ids' => [(string) $otherDestination->id],
        ])
        ->assertRedirect(route('app.settings'))
        ->assertSessionHasErrors(['allowed_site_ids']);

    expect(AgenticMarketingExecutionSetting::query()
        ->where('workspace_id', $workspace->id)
        ->exists())->toBeFalse();
});

it('requires owner-level permission to update execution mode settings', function () {
    [, , $organization] = makeAgenticExecutionSettingsContext();
    $editor = User::query()->create([
        'name' => 'Execution Editor',
        'email' => 'execution-editor+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'editor',
        'active' => true,
        'approved_at' => now(),
    ]);

    $this->actingAs($editor)
        ->post(route('app.settings.agentic-marketing-execution.update'), [
            'agentic_execution_mode' => 'guided',
            'max_autonomous_actions_per_day' => 3,
            'max_autonomous_credits_per_month' => 100,
            'require_approval_above_priority_score' => 80,
        ])
        ->assertStatus(403);

    expect(AgenticMarketingExecutionSetting::query()->count())->toBe(0);
});

function makeAgenticExecutionSettingsContext(string $slug = 'agentic-execution-settings'): array
{
    $organization = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => Str::headline($slug),
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => Str::headline($slug).' Workspace',
        'display_name' => Str::headline($slug).' Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'agentic-execution-settings-plan'],
        [
            'name' => 'Agentic Execution Settings Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 1000,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 1000,
        'seat_limit' => 5,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Primary Site',
        'site_url' => 'https://'.$slug.'.example.test',
        'base_url' => 'https://'.$slug.'.example.test',
        'allowed_domains' => [$slug.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Execution Owner',
        'email' => 'execution-owner+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$user, $workspace, $organization, $site];
}
