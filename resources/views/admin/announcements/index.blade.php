@extends('layouts.admin', ['title' => 'Announcements'])

@section('content')
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Announcements</h1>
            <p class="mt-1 text-textSecondary">Recent workspace announcements pushed into the client notification bell.</p>
        </div>
        <a href="{{ route('admin.announcements.create') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">
            Create announcement
        </a>
    </div>

    <div class="rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Last 50 announcements</h2>
        <div class="mt-3 space-y-3">
            @forelse ($announcements as $announcement)
                <div class="rounded border border-border p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium text-textPrimary">{{ $announcement->title }}</p>
                        <span class="rounded border border-border px-2 py-0.5 text-[11px] uppercase tracking-wide text-textSecondary">
                            {{ $announcement->workspace?->name ?? 'Workspace' }}
                        </span>
                    </div>
                    @if ($announcement->body)
                        <p class="mt-1 text-sm text-textSecondary">{{ $announcement->body }}</p>
                    @endif
                    <p class="mt-1 text-xs text-textFaint">
                        {{ optional($announcement->created_at)->toDateTimeString() }}
                        @if ($announcement->createdByAdmin)
                            · by {{ $announcement->createdByAdmin->name }}
                        @endif
                    </p>
                </div>
            @empty
                <p class="text-sm text-textSecondary">No announcements yet.</p>
            @endforelse
        </div>
    </div>
@endsection
