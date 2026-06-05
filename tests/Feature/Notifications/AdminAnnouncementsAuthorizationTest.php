<?php

use App\Models\Notification as WorkspaceNotification;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows admin area users to publish announcements to selected workspaces', function () {
    [$workspaceA, $workspaceB, $admin] = makeAnnouncementFixture();

    $this->actingAs($admin)
        ->post(route('admin.announcements.store'), [
            'target' => 'selected',
            'workspace_ids' => [(string) $workspaceA->id, (string) $workspaceB->id],
            'title' => 'Scheduled maintenance',
            'body' => 'We will deploy updates at 23:00.',
            'cta_label' => 'Open status page',
            'cta_url' => 'https://status.argusly.local',
        ])
        ->assertRedirect();

    $announcements = WorkspaceNotification::query()
        ->workspaceScoped()
        ->where('type', WorkspaceNotification::TYPE_ANNOUNCEMENT)
        ->where('title', 'Scheduled maintenance')
        ->whereIn('workspace_id', [(string) $workspaceA->id, (string) $workspaceB->id])
        ->get();

    expect($announcements)->toHaveCount(2);
    expect($announcements->pluck('workspace_id')->all())->toContain((string) $workspaceA->id);
    expect($announcements->pluck('workspace_id')->all())->toContain((string) $workspaceB->id);
    expect($announcements->firstWhere('workspace_id', (string) $workspaceA->id)?->created_by_admin_id)->toBe($admin->id);
    expect((string) $announcements->first()?->cta_label)->toBe('Open status page');
});

it('forbids non-admin users from publishing announcements', function () {
    [$workspace] = makeAnnouncementFixture();

    $clientOrg = Organization::query()->create([
        'name' => 'Client Org',
        'slug' => 'client-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $client = User::query()->create([
        'name' => 'Client User',
        'email' => 'client-announcements+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $clientOrg->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $this->actingAs($client)
        ->post(route('admin.announcements.store'), [
            'target' => 'selected',
            'workspace_ids' => [(string) $workspace->id],
            'title' => 'Unauthorized announcement',
        ])
        ->assertForbidden();
});

it('limits non-superadmin announcement publishing to three batches per 24 hours', function () {
    [$workspaceA, $workspaceB, $admin] = makeAnnouncementFixture();

    foreach ([1, 2, 3] as $index) {
        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), [
                'target' => 'selected',
                'workspace_ids' => [(string) $workspaceA->id, (string) $workspaceB->id],
                'title' => 'Announcement batch ' . $index,
            ])
            ->assertRedirect();
    }

    $this->actingAs($admin)
        ->from(route('admin.announcements.index'))
        ->post(route('admin.announcements.store'), [
            'target' => 'selected',
            'workspace_ids' => [(string) $workspaceA->id],
            'title' => 'Announcement batch 4',
        ])
        ->assertRedirect(route('admin.announcements.index'))
        ->assertSessionHasErrors(['announcements']);
});

function makeAnnouncementFixture(): array
{
    $workspaceOrg = Organization::query()->create([
        'name' => 'Announcement Workspace Org',
        'slug' => 'announcement-workspace-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $adminOrg = Organization::query()->create([
        'name' => 'Admin Org',
        'slug' => 'announcement-admin-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspaceA = Workspace::query()->create([
        'name' => 'Announcement Workspace A',
        'organization_id' => $workspaceOrg->id,
    ]);

    $workspaceB = Workspace::query()->create([
        'name' => 'Announcement Workspace B',
        'organization_id' => $workspaceOrg->id,
    ]);

    $admin = User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin-announcements+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $adminOrg->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);

    return [$workspaceA, $workspaceB, $admin];
}
