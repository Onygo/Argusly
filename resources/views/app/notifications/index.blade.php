@extends('layouts.app', ['title' => 'Notifications'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Notifications</x-slot:title>
        <x-slot:description>Action required, system updates, and announcements.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">

        @if ($filters['workspace_id'] !== '')
            <form method="POST" action="{{ route('app.notifications.read-all') }}">
                @csrf
                <input type="hidden" name="workspace_id" value="{{ $filters['workspace_id'] }}">
                <button type="submit" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">
                    Mark all read
                </button>
            </form>
        @endif
    </div>

    <form method="GET" class="mb-4 grid gap-2 md:grid-cols-4">
        <select name="workspace_id" class="pl-select bg-surface">
            @foreach ($workspaces as $workspace)
                <option value="{{ $workspace->id }}" @selected($filters['workspace_id'] === (string) $workspace->id)>{{ $workspace->name }}</option>
            @endforeach
        </select>

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

        <div class="flex gap-2">
            <button class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Filter</button>
            <a href="{{ route('app.notifications.index') }}" class="rounded border border-border px-3 py-2 text-sm text-textSecondary hover:bg-surfaceSubtle">Reset</a>
        </div>
    </form>

    <div class="space-y-3">
        @forelse ($notifications as $notification)
            <div class="rounded-lg border border-border bg-surface p-4 {{ $notification->read_at ? '' : 'ring-1 ring-accentYellow-300/40' }}">
                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-textPrimary">{{ $notification->title }}</span>
                            @if (! $notification->read_at)
                                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-primary"></span>
                            @endif
                            <span class="rounded border border-border px-2 py-0.5 text-[11px] uppercase tracking-wide text-textSecondary">{{ str_replace('_', ' ', $notification->type) }}</span>
                        </div>
                        @if ($notification->body)
                            <p class="mt-1 text-sm text-textSecondary">{{ $notification->body }}</p>
                        @endif
                        <p class="mt-1 text-xs text-textFaint">{{ optional($notification->created_at)->diffForHumans() }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 md:justify-end">
                        @if ($notification->cta_url && $notification->cta_label)
                            <a href="{{ $notification->cta_url }}" class="rounded border border-border px-3 py-1.5 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                {{ $notification->cta_label }}
                            </a>
                        @endif
                        @if (! $notification->read_at)
                            <form method="POST" action="{{ route('app.notifications.read', $notification) }}">
                                @csrf
                                <button type="submit" class="rounded border border-border px-3 py-1.5 text-xs text-textSecondary hover:bg-surfaceSubtle">Mark read</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-surface p-6 text-sm text-textSecondary">
                No notifications found.
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $notifications->links() }}</div>
@endsection
