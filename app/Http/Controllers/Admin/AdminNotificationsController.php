<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Workspace;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminNotificationsController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request): View
    {
        Gate::forUser($request->user())->authorize('admin-notifications.view-any');

        $type = trim((string) $request->query('type', ''));
        if (! in_array($type, [Notification::TYPE_ACTION_REQUIRED, Notification::TYPE_SYSTEM], true)) {
            $type = '';
        }

        $unreadOnly = $request->boolean('unread_only');

        $items = $this->notifications->adminVisibleQueryForUser($request->user())
            ->with(['workspace:id,name,display_name'])
            ->when($type !== '', fn ($builder) => $builder->ofType($type))
            ->when($unreadOnly, fn ($builder) => $builder->unread())
            ->orderedForBell()
            ->paginate(20)
            ->withQueryString();

        return view('admin.notifications.index', [
            'notifications' => $items,
            'filters' => [
                'type' => $type,
                'unread_only' => $unreadOnly,
            ],
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse|RedirectResponse
    {
        Gate::forUser($request->user())->authorize('admin-notifications.update', $notification);
        $this->notifications->markAdminRead((string) $notification->id, $request->user());

        if ($request->expectsJson()) {
            return $this->adminBellJsonResponse($request);
        }

        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        Gate::forUser($request->user())->authorize('admin-notifications.view-any');
        $this->notifications->markAllAdminRead($request->user());

        if ($request->expectsJson()) {
            return $this->adminBellJsonResponse($request);
        }

        return back()->with('status', 'All admin notifications marked as read.');
    }

    public function workspace(Request $request, Workspace $workspace): View
    {
        Gate::forUser($request->user())->authorize('admin-area-manage-approvals');

        $type = trim((string) $request->query('type', ''));
        if (! in_array($type, Notification::allowedTypes(), true)) {
            $type = '';
        }

        $unreadOnly = $request->boolean('unread_only');

        $items = Notification::query()
            ->workspaceScoped()
            ->where('workspace_id', (string) $workspace->id)
            ->with(['user:id,name', 'createdByAdmin:id,name'])
            ->when($type !== '', fn ($builder) => $builder->ofType($type))
            ->when($unreadOnly, fn ($builder) => $builder->unread())
            ->orderedForBell()
            ->paginate(20)
            ->withQueryString();

        return view('admin.workspaces.notifications', [
            'workspace' => $workspace->load('organization:id,name'),
            'notifications' => $items,
            'filters' => [
                'type' => $type,
                'unread_only' => $unreadOnly,
            ],
        ]);
    }

    private function adminBellJsonResponse(Request $request): JsonResponse
    {
        $notificationBell = $this->notifications->adminBellDataForUser($request->user());

        return response()->json([
            'message' => 'Notifications updated.',
            'notificationBell' => [
                'unread_count' => (int) $notificationBell['unread_count'],
            ],
            'menu_html' => view('partials.notifications.admin-bell-menu', [
                'notificationBell' => $notificationBell,
            ])->render(),
        ]);
    }
}
