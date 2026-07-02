@extends('layouts.admin', ['title' => 'Announcements'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Announcements</x-slot:title>
        <x-slot:description>Recent workspace announcements pushed into the client notification bell.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        <a href="{{ route('admin.announcements.create') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">
            Create announcement
        </a>
@endsection

@section('content')

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
