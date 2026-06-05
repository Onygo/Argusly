<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeTeamMemberContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Team Member Test Org',
        'slug' => 'tm-test-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Team Member BV',
        'billing_address_line1' => 'Teststraat 12',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Team Member Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'tm-test-plan'],
        [
            'name' => 'Team Member Test Plan',
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
        'name' => 'Team Member Owner',
        'email' => 'tm-owner+'.Str::random(5).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $member = User::query()->create([
        'name' => 'Team Member User',
        'email' => 'tm-member+'.Str::random(5).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'member',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $owner, $member];
}

describe('Team Member Index', function () {
    it('allows authenticated users to view team members page', function () {
        [, , $owner] = makeTeamMemberContext();

        $this->actingAs($owner)
            ->get(route('app.brand.team-members'))
            ->assertOk()
            ->assertSee('Team Member Personas');
    });

    it('shows team members for the organization', function () {
        [$organization, , $owner] = makeTeamMemberContext();

        TeamMember::query()->create([
            'organization_id' => $organization->id,
            'name' => 'John Writer',
            'role' => 'Content Strategist',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('app.brand.team-members'))
            ->assertOk()
            ->assertSee('John Writer');
    });

    it('does not show team members from other organizations', function () {
        [, , $owner] = makeTeamMemberContext();

        $otherOrg = Organization::query()->create([
            'name' => 'Other Org',
            'slug' => 'other-org-'.Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'billing_company_name' => 'Other BV',
            'billing_address_line1' => 'Other 12',
            'billing_country_code' => 'NL',
        ]);

        TeamMember::query()->create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Org Writer',
            'role' => 'Writer',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('app.brand.team-members'))
            ->assertOk()
            ->assertDontSee('Other Org Writer');
    });
});

describe('Team Member Store', function () {
    it('allows owners to create team members', function () {
        [$organization, , $owner] = makeTeamMemberContext();

        $this->actingAs($owner)
            ->post(route('app.brand.team-members.store'), [
                'name' => 'Jane Expert',
                'role' => 'Content Strategist',
                'expertise' => 'B2B SaaS marketing',
                'writing_perspective' => 'First-person expert',
                'personality_traits' => 'Analytical, thorough',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Team member persona created.');

        $this->assertDatabaseHas('team_members', [
            'organization_id' => $organization->id,
            'name' => 'Jane Expert',
            'role' => 'Content Strategist',
            'is_active' => true,
        ]);
    });

    it('creates team member with only required name field', function () {
        [$organization, , $owner] = makeTeamMemberContext();

        $this->actingAs($owner)
            ->post(route('app.brand.team-members.store'), [
                'name' => 'Minimal Writer',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('team_members', [
            'organization_id' => $organization->id,
            'name' => 'Minimal Writer',
            'is_active' => true,
        ]);
    });

    it('prevents regular members from creating team members', function () {
        [, , , $member] = makeTeamMemberContext();

        $this->actingAs($member)
            ->post(route('app.brand.team-members.store'), [
                'name' => 'Unauthorized Writer',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('team_members', [
            'name' => 'Unauthorized Writer',
        ]);
    });

    it('validates name is required', function () {
        [, , $owner] = makeTeamMemberContext();

        $this->actingAs($owner)
            ->post(route('app.brand.team-members.store'), [
                'role' => 'Writer',
            ])
            ->assertSessionHasErrors('name');
    });
});

describe('Team Member Update', function () {
    it('allows owners to update team members', function () {
        [$organization, , $owner] = makeTeamMemberContext();

        $teamMember = TeamMember::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Original Name',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('app.brand.team-members.update', $teamMember), [
                'name' => 'Updated Name',
                'role' => 'Senior Writer',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Team member persona updated.');

        $this->assertDatabaseHas('team_members', [
            'id' => $teamMember->id,
            'name' => 'Updated Name',
            'role' => 'Senior Writer',
        ]);
    });

    it('prevents updating team members from other organizations', function () {
        [, , $owner] = makeTeamMemberContext();

        $otherOrg = Organization::query()->create([
            'name' => 'Other Org',
            'slug' => 'other-org-'.Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'billing_company_name' => 'Other BV',
            'billing_address_line1' => 'Other 12',
            'billing_country_code' => 'NL',
        ]);

        $otherMember = TeamMember::query()->create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Member',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('app.brand.team-members.update', $otherMember), [
                'name' => 'Hacked Name',
            ])
            ->assertNotFound();
    });

    it('prevents regular members from updating team members', function () {
        [$organization, , , $member] = makeTeamMemberContext();

        $teamMember = TeamMember::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Some Member',
            'is_active' => true,
        ]);

        $this->actingAs($member)
            ->post(route('app.brand.team-members.update', $teamMember), [
                'name' => 'Unauthorized Update',
            ])
            ->assertForbidden();
    });
});

describe('Team Member Toggle Active', function () {
    it('allows owners to deactivate team members', function () {
        [$organization, , $owner] = makeTeamMemberContext();

        $teamMember = TeamMember::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Active Member',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('app.brand.team-members.toggle', $teamMember))
            ->assertRedirect()
            ->assertSessionHas('status', 'Team member persona deactivated.');

        $this->assertDatabaseHas('team_members', [
            'id' => $teamMember->id,
            'is_active' => false,
        ]);
    });

    it('allows owners to reactivate team members', function () {
        [$organization, , $owner] = makeTeamMemberContext();

        $teamMember = TeamMember::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Inactive Member',
            'is_active' => false,
        ]);

        $this->actingAs($owner)
            ->post(route('app.brand.team-members.toggle', $teamMember))
            ->assertRedirect()
            ->assertSessionHas('status', 'Team member persona activated.');

        $this->assertDatabaseHas('team_members', [
            'id' => $teamMember->id,
            'is_active' => true,
        ]);
    });

    it('prevents toggling team members from other organizations', function () {
        [, , $owner] = makeTeamMemberContext();

        $otherOrg = Organization::query()->create([
            'name' => 'Other Org',
            'slug' => 'other-org-'.Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'billing_company_name' => 'Other BV',
            'billing_address_line1' => 'Other 12',
            'billing_country_code' => 'NL',
        ]);

        $otherMember = TeamMember::query()->create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Member',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('app.brand.team-members.toggle', $otherMember))
            ->assertNotFound();
    });

    it('prevents regular members from toggling team members', function () {
        [$organization, , , $member] = makeTeamMemberContext();

        $teamMember = TeamMember::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Some Member',
            'is_active' => true,
        ]);

        $this->actingAs($member)
            ->post(route('app.brand.team-members.toggle', $teamMember))
            ->assertForbidden();
    });
});

describe('Team Member Policy', function () {
    it('allows admins to manage team members', function () {
        [$organization] = makeTeamMemberContext();

        $admin = User::query()->create([
            'name' => 'Team Admin',
            'email' => 'tm-admin+'.Str::random(5).'@example.com',
            'password' => bcrypt('secret'),
            'organization_id' => $organization->id,
            'role' => 'admin',
            'active' => true,
            'approved_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('app.brand.team-members.store'), [
                'name' => 'Admin Created',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('team_members', [
            'name' => 'Admin Created',
        ]);
    });
});
