<?php

use App\Events\Notifications\SiteVerified;
use App\Events\Notifications\DraftDeliveryFailed;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Notification as WorkspaceNotification;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates workspace-wide and user-specific notifications', function () {
    [$organization, $workspace] = makeNotificationWorkspaceContext();

    $user = User::query()->create([
        'name' => 'Notif User',
        'email' => 'notif-user+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    /** @var NotificationService $service */
    $service = app(NotificationService::class);

    $workspaceWide = $service->notifyWorkspace(
        workspaceId: (string) $workspace->id,
        type: WorkspaceNotification::TYPE_SYSTEM,
        title: 'Workspace system update',
        body: 'Connector heartbeat recovered.'
    );

    $userSpecific = $service->notifyUser(
        userId: (int) $user->id,
        workspaceId: (string) $workspace->id,
        type: WorkspaceNotification::TYPE_ACTION_REQUIRED,
        title: 'Review required',
        body: 'Draft needs your review.'
    );

    expect($workspaceWide->user_id)->toBeNull();
    expect((string) $workspaceWide->target_scope)->toBe(WorkspaceNotification::TARGET_SCOPE_WORKSPACE);
    expect((bool) $workspaceWide->is_admin_only)->toBeFalse();
    expect((int) $workspaceWide->priority)->toBe(WorkspaceNotification::PRIORITY_SYSTEM);
    expect((int) $userSpecific->user_id)->toBe((int) $user->id);
    expect((int) $userSpecific->priority)->toBe(WorkspaceNotification::PRIORITY_ACTION_REQUIRED);
});

it('uses a database-backed dedupe key so concurrent notification creates cannot duplicate rows', function () {
    [$organization, $workspace] = makeNotificationWorkspaceContext();

    $user = User::query()->create([
        'name' => 'Notif Dedupe User',
        'email' => 'notif-dedupe+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $service = app(NotificationService::class);
    $options = [
        'dedupe_key' => 'notification-service-test:'.$workspace->id.':'.$user->id,
        'meta' => ['source' => 'notification_service_test'],
    ];

    $first = $service->notifyUser(
        userId: (int) $user->id,
        workspaceId: (string) $workspace->id,
        type: WorkspaceNotification::TYPE_SYSTEM,
        title: 'Dedupe test',
        body: 'Only one row should exist.',
        options: $options,
    );

    expect($first->dedupe_key)->toBe($options['dedupe_key'])
        ->and($first->dedupe_scope)->not->toBeEmpty()
        ->and(data_get($first->meta, 'dedupe_key'))->toBe($options['dedupe_key']);

    expect(fn () => WorkspaceNotification::query()->create([
        'workspace_id' => (string) $workspace->id,
        'target_scope' => WorkspaceNotification::TARGET_SCOPE_WORKSPACE,
        'is_admin_only' => false,
        'user_id' => (int) $user->id,
        'type' => WorkspaceNotification::TYPE_SYSTEM,
        'title' => 'Concurrent duplicate',
        'body' => 'This simulates the losing insert in a race.',
        'priority' => WorkspaceNotification::PRIORITY_SYSTEM,
        'dedupe_key' => $first->dedupe_key,
        'dedupe_scope' => $first->dedupe_scope,
        'meta' => ['dedupe_key' => $first->dedupe_key],
    ]))->toThrow(QueryException::class);

    $second = $service->notifyUser(
        userId: (int) $user->id,
        workspaceId: (string) $workspace->id,
        type: WorkspaceNotification::TYPE_SYSTEM,
        title: 'Dedupe test retry',
        body: 'The service should return the existing row.',
        options: $options,
    );

    expect($second->id)->toBe($first->id)
        ->and(WorkspaceNotification::query()->where('dedupe_key', $options['dedupe_key'])->count())->toBe(1);
});

it('keeps admin scoped and workspace scoped notifications strictly separated', function () {
    [$organization, $workspace] = makeNotificationWorkspaceContext();

    $client = User::query()->create([
        'name' => 'Client Visibility User',
        'email' => 'notif-client-visibility+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $adminOrg = Organization::query()->create([
        'name' => 'Admin Visibility Org',
        'slug' => 'admin-visibility-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $admin = User::query()->create([
        'name' => 'Admin Visibility User',
        'email' => 'notif-admin-visibility+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $adminOrg->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);

    $service = app(NotificationService::class);

    $workspaceNotification = $service->notifyWorkspace((string) $workspace->id, WorkspaceNotification::TYPE_SYSTEM, 'Workspace scope');
    $adminNotification = $service->notifyAdmin(
        type: WorkspaceNotification::TYPE_ACTION_REQUIRED,
        title: 'Admin scope',
        body: 'Connector issue detected.',
        options: [
            'workspace_id' => (string) $workspace->id,
            'meta' => ['workspace_id' => (string) $workspace->id],
        ]
    );

    $clientVisible = $service->visibleQueryForUser($client)->pluck('id')->all();
    expect($clientVisible)->toContain((string) $workspaceNotification->id);
    expect($clientVisible)->not->toContain((string) $adminNotification->id);

    $adminVisible = $service->adminVisibleQueryForUser($admin)->pluck('id')->all();
    expect($adminVisible)->toContain((string) $adminNotification->id);
    expect($adminVisible)->not->toContain((string) $workspaceNotification->id);
});

it('scopes visibility by workspace and user visibility rules', function () {
    [$organization, $workspaceA, $workspaceB] = makeNotificationWorkspaceContext(twoWorkspaces: true);

    $userA = User::query()->create([
        'name' => 'User A',
        'email' => 'notif-user-a+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $userB = User::query()->create([
        'name' => 'User B',
        'email' => 'notif-user-b+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'member',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $otherOrg = Organization::query()->create([
        'name' => 'Other Org',
        'slug' => 'other-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $otherWorkspace = Workspace::query()->create([
        'name' => 'Other Workspace',
        'organization_id' => $otherOrg->id,
    ]);

    $service = app(NotificationService::class);

    $aWorkspaceWide = $service->notifyWorkspace((string) $workspaceA->id, WorkspaceNotification::TYPE_SYSTEM, 'A-wide');
    $aUserAOnly = $service->notifyUser((int) $userA->id, (string) $workspaceA->id, WorkspaceNotification::TYPE_SYSTEM, 'A-user-a');
    $aUserBOnly = $service->notifyUser((int) $userB->id, (string) $workspaceA->id, WorkspaceNotification::TYPE_SYSTEM, 'A-user-b');
    $bWorkspaceWide = $service->notifyWorkspace((string) $workspaceB->id, WorkspaceNotification::TYPE_SYSTEM, 'B-wide');
    $otherWorkspaceWide = $service->notifyWorkspace((string) $otherWorkspace->id, WorkspaceNotification::TYPE_SYSTEM, 'Other-wide');

    $workspaceAIds = $service->visibleQueryForUser($userA, (string) $workspaceA->id)->pluck('id')->all();
    expect($workspaceAIds)->toContain((string) $aWorkspaceWide->id);
    expect($workspaceAIds)->toContain((string) $aUserAOnly->id);
    expect($workspaceAIds)->not->toContain((string) $aUserBOnly->id);
    expect($workspaceAIds)->not->toContain((string) $bWorkspaceWide->id);
    expect($workspaceAIds)->not->toContain((string) $otherWorkspaceWide->id);

    $allVisibleIds = $service->visibleQueryForUser($userA)->pluck('id')->all();
    expect($allVisibleIds)->toContain((string) $aWorkspaceWide->id);
    expect($allVisibleIds)->toContain((string) $aUserAOnly->id);
    expect($allVisibleIds)->toContain((string) $bWorkspaceWide->id);
    expect($allVisibleIds)->not->toContain((string) $aUserBOnly->id);
    expect($allVisibleIds)->not->toContain((string) $otherWorkspaceWide->id);
});

it('marks single notifications read and supports mark all read with scoping', function () {
    [$organization, $workspace] = makeNotificationWorkspaceContext();

    $user = User::query()->create([
        'name' => 'Mark Read User',
        'email' => 'notif-mark-read+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $otherOrg = Organization::query()->create([
        'name' => 'Outsider Org',
        'slug' => 'outsider-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $outsider = User::query()->create([
        'name' => 'Outsider',
        'email' => 'notif-outsider+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $otherOrg->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $service = app(NotificationService::class);

    $first = $service->notifyWorkspace((string) $workspace->id, WorkspaceNotification::TYPE_SYSTEM, 'First');
    $second = $service->notifyUser((int) $user->id, (string) $workspace->id, WorkspaceNotification::TYPE_ACTION_REQUIRED, 'Second');
    $third = $service->notifyWorkspace((string) $workspace->id, WorkspaceNotification::TYPE_ANNOUNCEMENT, 'Third');

    $service->markRead((string) $first->id, $user);
    $first->refresh();
    expect($first->read_at)->not->toBeNull();

    expect(fn () => $service->markRead((string) $second->id, $outsider))
        ->toThrow(AuthorizationException::class);

    $updated = $service->markAllRead((string) $workspace->id, $user);
    expect($updated)->toBe(2);

    $unreadCount = WorkspaceNotification::query()
        ->where('workspace_id', (string) $workspace->id)
        ->whereNull('read_at')
        ->count();
    expect($unreadCount)->toBe(0);

    expect($service->unreadCount((string) $workspace->id, $user))->toBe(0);
    expect($third->fresh()?->read_at)->not->toBeNull();
});

it('creates system notification from site verified event listener', function () {
    [, $workspace] = makeNotificationWorkspaceContext();

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Listener Test Site',
        'site_url' => 'https://listener-test.example.com',
        'allowed_domains' => ['listener-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    event(new SiteVerified((string) $site->id, 'wordpress'));

    $notification = WorkspaceNotification::query()
        ->where('workspace_id', (string) $workspace->id)
        ->where('type', WorkspaceNotification::TYPE_SYSTEM)
        ->where('title', 'Site connection verified')
        ->latest('created_at')
        ->first();

    expect($notification)->not->toBeNull();
    expect((string) data_get($notification?->meta, 'site_id'))->toBe((string) $site->id);
    expect((string) data_get($notification?->meta, 'channel'))->toBe('wordpress');
});

it('creates admin scoped delivery failure notification with workspace and site meta', function () {
    [$organization, $workspace] = makeNotificationWorkspaceContext();

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Delivery Fail Site',
        'site_url' => 'https://delivery-fail.example.com',
        'allowed_domains' => ['delivery-fail.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Delivery fail content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'title' => 'Delivery fail brief',
        'status' => 'ready',
        'source' => 'client_ui',
        'output_type' => 'kb_article',
    ]);

    $draft = \App\Models\Draft::query()->create([
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready_to_deliver',
        'title' => 'Delivery fail draft',
        'output_type' => 'kb_article',
    ]);

    event(new DraftDeliveryFailed((string) $draft->id, 'Timeout from connector'));

    $adminNotification = WorkspaceNotification::query()
        ->adminScoped()
        ->where('type', WorkspaceNotification::TYPE_ACTION_REQUIRED)
        ->where('meta->draft_id', (string) $draft->id)
        ->latest('created_at')
        ->first();

    expect($adminNotification)->not->toBeNull();
    expect((string) data_get($adminNotification?->meta, 'workspace_id'))->toBe((string) $workspace->id);
    expect((string) data_get($adminNotification?->meta, 'site_id'))->toBe((string) $site->id);
    expect((string) data_get($adminNotification?->meta, 'content_id'))->toBe((string) $content->id);
});

function makeNotificationWorkspaceContext(bool $twoWorkspaces = false): array
{
    $organization = Organization::query()->create([
        'name' => 'Notification Org',
        'slug' => 'notification-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspaceA = Workspace::query()->create([
        'name' => 'Notification Workspace A',
        'organization_id' => $organization->id,
    ]);

    if (! $twoWorkspaces) {
        return [$organization, $workspaceA];
    }

    $workspaceB = Workspace::query()->create([
        'name' => 'Notification Workspace B',
        'organization_id' => $organization->id,
    ]);

    return [$organization, $workspaceA, $workspaceB];
}
