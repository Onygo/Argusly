<?php

use App\Models\AgentRun;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows agent runs in the admin area', function () {
    $admin = makeAdminAgentRunsUser();

    AgentRun::query()->create([
        'agent_key' => 'draft.smart-suggestions',
        'trigger_type' => 'manual',
        'trigger_source' => 'admin.debug',
        'status' => 'success',
        'organization_id' => $admin->organization_id,
        'user_id' => $admin->id,
        'input_payload' => ['draft_id' => 'draft-123'],
        'output_payload' => ['summary' => 'Two suggestions'],
        'summary' => 'Two suggestions',
        'started_at' => now()->subSeconds(2),
        'finished_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.agent-runs.index'))
        ->assertOk()
        ->assertSee('Agent Runs')
        ->assertSee('draft.smart-suggestions')
        ->assertSee('Two suggestions')
        ->assertSee('manual');
});

function makeAdminAgentRunsUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Agent Runs Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-agent-runs-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Admin Agent Runs User',
        'email' => 'admin-agent-runs+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}
