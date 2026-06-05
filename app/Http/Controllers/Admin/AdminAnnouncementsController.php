<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Workspace;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminAnnouncementsController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request): View
    {
        Gate::forUser($request->user())->authorize('admin-notifications.create-announcement');

        $announcements = Notification::query()
            ->workspaceScoped()
            ->where('type', Notification::TYPE_ANNOUNCEMENT)
            ->with(['workspace:id,name,organization_id', 'workspace.organization:id,name', 'createdByAdmin:id,name'])
            ->latest('created_at')
            ->limit(50)
            ->get();

        return view('admin.announcements.index', [
            'announcements' => $announcements,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::forUser($request->user())->authorize('admin-notifications.create-announcement');

        $workspaces = Workspace::query()
            ->with('organization:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'organization_id']);

        return view('admin.announcements.create', [
            'workspaces' => $workspaces,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('admin-notifications.create-announcement');

        $data = $request->validate([
            'target' => ['required', 'in:all,selected'],
            'workspace_ids' => ['nullable', 'array'],
            'workspace_ids.*' => ['required', 'uuid', 'exists:workspaces,id'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:1000'],
            'cta_label' => ['nullable', 'string', 'max:40'],
            'cta_url' => ['nullable', 'string', 'max:1000'],
            'priority' => ['nullable', 'integer', 'between:1,999'],
        ]);

        $data['cta_url'] = $this->normalizeCtaUrl($data['cta_url'] ?? null);

        $workspaceIds = $this->resolveWorkspaceTargets($data);
        if ($workspaceIds === []) {
            return back()->withErrors(['workspace_ids' => 'Select at least one workspace for the announcement.'])->withInput();
        }

        $actor = $request->user();
        if (! $actor->isSuperadmin()) {
            $recentBatches = Notification::query()
                ->workspaceScoped()
                ->where('type', Notification::TYPE_ANNOUNCEMENT)
                ->where('created_by_admin_id', (int) $actor->id)
                ->where('created_at', '>=', now()->subDay())
                ->get(['id', 'meta']);

            $recentCount = $recentBatches
                ->map(fn (Notification $notification): string => (string) (data_get($notification->meta, 'announcement_batch_id') ?: $notification->id))
                ->unique()
                ->count();

            if ($recentCount >= 3) {
                return back()->withErrors(['announcements' => 'Announcement limit reached (3 per 24 hours).'])->withInput();
            }
        }

        $batchId = (string) Str::uuid();

        foreach ($workspaceIds as $workspaceId) {
            $this->notifications->notifyWorkspace(
                workspaceId: $workspaceId,
                type: Notification::TYPE_ANNOUNCEMENT,
                title: (string) $data['title'],
                body: isset($data['body']) ? (string) $data['body'] : null,
                options: [
                    'cta_label' => $data['cta_label'] ?? null,
                    'cta_url' => $data['cta_url'] ?? null,
                    'priority' => isset($data['priority']) ? (int) $data['priority'] : null,
                    'created_by_admin_id' => (int) $actor->id,
                    'meta' => [
                        'source' => 'admin.announcements',
                        'announcement_batch_id' => $batchId,
                        'announcement_target' => (string) $data['target'],
                    ],
                ]
            );
        }

        return redirect()->route('admin.announcements.index')
            ->with('status', 'Announcement published to ' . count($workspaceIds) . ' workspace(s).');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,string>
     */
    private function resolveWorkspaceTargets(array $data): array
    {
        $target = (string) ($data['target'] ?? 'selected');
        if ($target === 'all') {
            return Workspace::query()->pluck('id')->all();
        }

        return collect($data['workspace_ids'] ?? [])
            ->map(fn ($id): string => (string) $id)
            ->filter(fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeCtaUrl(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (Str::startsWith($raw, '/')) {
            return $raw;
        }

        if (filter_var($raw, FILTER_VALIDATE_URL) !== false) {
            return $raw;
        }

        throw ValidationException::withMessages([
            'cta_url' => 'CTA URL must be a valid absolute URL or an internal path that starts with /.',
        ]);
    }
}
