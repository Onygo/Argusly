@extends('layouts.admin', ['title' => 'Workspace Notifications'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Workspace Notifications</h1>
        <p class="mt-1 text-textSecondary">
            {{ $workspace->display_name ?: $workspace->name }}
            @if ($workspace->organization)
                · {{ $workspace->organization->name }}
            @endif
        </p>
    </div>

    <form method="GET" class="mb-4 grid gap-2 md:grid-cols-4">
        <select name="type" class="pl-select bg-surface">
            <option value="">All types</option>
            <option value="action_required" @selected($filters['type'] === 'action_required')>Action required</option>
            <option value="announcement" @selected($filters['type'] === 'announcement')>Announcement</option>
            <option value="system" @selected($filters['type'] === 'system')>System</option>
        </select>

        <label class="flex items-center gap-2 rounded border border-border px-3 py-2 text-sm text-textSecondary">
            <input type="checkbox" name="unread_only" value="1" @checked($filters['unread_only'])>
            Unread only
        </label>

        <div class="flex gap-2 md:col-span-2">
            <button class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Filter</button>
            <a href="{{ route('admin.workspaces.notifications', $workspace) }}" class="rounded border border-border px-3 py-2 text-sm text-textSecondary hover:bg-surfaceSubtle">Reset</a>
        </div>
    </form>

    <div class="space-y-3">
        @forelse ($notifications as $notification)
            <div class="rounded-lg border border-border bg-surface p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-textPrimary">{{ $notification->title }}</span>
                            <span class="rounded border border-border px-2 py-0.5 text-[11px] uppercase tracking-wide text-textSecondary">{{ str_replace('_', ' ', $notification->type) }}</span>
                            @if (! $notification->read_at)
                                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-primary"></span>
                            @endif
                        </div>
                        @if ($notification->body)
                            <p class="mt-1 text-sm text-textSecondary">{{ $notification->body }}</p>
                        @endif
                        <p class="mt-1 text-xs text-textFaint">
                            {{ optional($notification->created_at)->diffForHumans() }}
                            @if ($notification->user)
                                · user: {{ $notification->user->name }}
                            @else
                                · workspace wide
                            @endif
                            @if ($notification->createdByAdmin)
                                · by admin: {{ $notification->createdByAdmin->name }}
                            @endif
                        </p>
                    </div>
                    @if ($notification->cta_url && $notification->cta_label)
                        <a href="{{ $notification->cta_url }}" class="rounded border border-border px-3 py-1.5 text-xs text-textPrimary hover:bg-surfaceSubtle">
                            {{ $notification->cta_label }}
                        </a>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-surface p-6 text-sm text-textSecondary">
                No workspace notifications found.
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $notifications->links() }}</div>
@endsection

