@extends('layouts.app', ['title' => 'Recommended Actions'])

@section('pageHeader')
    <x-page-header title="What Argusly recommends next" eyebrow="Recommended Actions Inbox">
        <x-slot:description>Unified actions from opportunities, learning, AI visibility, agentic marketing, campaign planning, content intelligence, and distribution.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('app.dashboard') }}" class="pl-btn-secondary">Command Center</a>
@endsection

@section('content')
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Recommended Actions Inbox</p>
            <h2 class="text-2xl font-semibold tracking-tight text-textPrimary">What Argusly recommends next</h2>
            <p class="mt-1 max-w-3xl text-textSecondary">Unified actions from opportunities, learning, AI visibility, agentic marketing, campaign planning, content intelligence, and distribution.</p>
        </div>
        <a href="{{ route('app.dashboard') }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
            <i data-lucide="layout-dashboard" class="h-4 w-4"></i>
            Command Center
        </a>
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">Open actions</p>
            <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'total', 0)) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">Critical</p>
            <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'critical', 0)) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">High impact</p>
            <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'high', 0)) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">Need approval</p>
            <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'approval_required', 0)) }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('app.recommended-actions.index') }}" class="mb-6 grid gap-3 rounded-lg border border-border bg-surface p-4 md:grid-cols-[1fr_1fr_auto]">
        <label class="block">
            <span class="text-xs font-semibold uppercase tracking-wide text-textFaint">Source</span>
            <select name="source_group" class="mt-1 w-full rounded-md border-border bg-background text-sm">
                <option value="">All sources</option>
                @foreach ($sourceGroups as $value => $label)
                    <option value="{{ $value }}" @selected($sourceGroup === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="text-xs font-semibold uppercase tracking-wide text-textFaint">Priority</span>
            <select name="priority" class="mt-1 w-full rounded-md border-border bg-background text-sm">
                <option value="">All priorities</option>
                @foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $label)
                    <option value="{{ $value }}" @selected($priority === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-semibold text-white hover:bg-primaryHover">
                <i data-lucide="filter" class="h-4 w-4"></i>
                Filter
            </button>
            <a href="{{ route('app.recommended-actions.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-border px-4 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Reset</a>
        </div>
    </form>

    <div class="space-y-4">
        @forelse ($actions as $action)
            <x-recommended-actions.card :action="$action" />
        @empty
            <div class="rounded-lg border border-dashed border-border bg-surface p-8 text-center">
                <p class="font-semibold text-textPrimary">No recommended actions found</p>
                <p class="mx-auto mt-1 max-w-lg text-sm text-textSecondary">Argusly will add actions here as it finds opportunities, learns from results, prepares content recommendations, or needs approval for execution.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $actions->links() }}
    </div>
@endsection
