<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Workspace;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AppNotificationsController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewAny', Notification::class);

        $workspaceIds = $this->notifications->resolveWorkspaceIdsForActor($user);

        $selectedWorkspaceId = trim((string) $request->query('workspace_id', ''));
        if ($selectedWorkspaceId === '' || ! in_array($selectedWorkspaceId, $workspaceIds, true)) {
            $selectedWorkspaceId = $workspaceIds[0] ?? '';
        }

        $type = trim((string) $request->query('type', ''));
        if (! in_array($type, Notification::allowedTypes(), true)) {
            $type = '';
        }
        $unreadOnly = $request->boolean('unread_only');

        $query = $selectedWorkspaceId !== ''
            ? $this->notifications->visibleQueryForUser($user, $selectedWorkspaceId)
            : Notification::query()->whereRaw('1=0');

        $items = $query
            ->when($type !== '', fn ($builder) => $builder->ofType($type))
            ->when($unreadOnly, fn ($builder) => $builder->unread())
            ->orderedForBell()
            ->paginate(20)
            ->withQueryString();

        $workspaces = Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('app.notifications.index', [
            'notifications' => $items,
            'workspaces' => $workspaces,
            'filters' => [
                'workspace_id' => $selectedWorkspaceId,
                'type' => $type,
                'unread_only' => $unreadOnly,
            ],
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse|RedirectResponse
    {
        Gate::forUser($request->user())->authorize('update', $notification);
        $this->notifications->markRead((string) $notification->id, $request->user());

        if ($request->expectsJson()) {
            return $this->appBellJsonResponse($request, (string) $notification->workspace_id);
        }

        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'uuid'],
        ]);

        $this->notifications->markAllRead((string) $data['workspace_id'], $request->user());

        if ($request->expectsJson()) {
            return $this->appBellJsonResponse($request, (string) $data['workspace_id']);
        }

        return back()->with('status', 'All notifications marked as read.');
    }

    private function appBellJsonResponse(Request $request, ?string $workspaceId = null): JsonResponse
    {
        $notificationBell = $this->notifications->appBellDataForUser($request->user(), $workspaceId);

        return response()->json([
            'message' => 'Notifications updated.',
            'notificationBell' => [
                'workspace_id' => $notificationBell['workspace_id'],
                'unread_count' => (int) $notificationBell['unread_count'],
            ],
            'menu_html' => view('partials.notifications.app-bell-menu', [
                'notificationBell' => $notificationBell,
            ])->render(),
        ]);
    }
}
