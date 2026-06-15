@extends('layouts.app', ['title' => 'Approval Conversations', 'pageWidth' => 'wide'])

@section('content')
    <div class="space-y-6">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('app.agentic-marketing.index') }}" class="text-sm text-textSecondary hover:text-textPrimary">Agentic Marketing</a>
                <h1 class="mt-2 text-xl font-semibold text-textPrimary">Approval Conversations</h1>
                <p class="mt-1 max-w-3xl text-sm text-textSecondary">Argusly recommends what can be approved, explains what needs judgment, and calls out blocked actions before they reach execution.</p>
            </div>
        </header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <x-approvals.summary :recommendation="$approvalRecommendation" />

        <section class="rounded-lg border border-border bg-surface p-4">
            <form method="GET" action="{{ route('app.agentic-marketing.approvals.index') }}" class="grid gap-3 md:grid-cols-4">
                <select name="workspace_id" class="pl-input text-sm">
                    <option value="">All workspaces</option>
                    @foreach ($workspaces as $workspace)
                        <option value="{{ $workspace->id }}" @selected(($filters['workspace_id'] ?? '') === (string) $workspace->id)>{{ $workspace->display_name ?? $workspace->name }}</option>
                    @endforeach
                </select>
                <select name="action_type" class="pl-input text-sm">
                    <option value="">All action types</option>
                    @foreach ($actionTypes as $type)
                        <option value="{{ $type }}" @selected(($filters['action_type'] ?? '') === $type)>{{ str_replace('_', ' ', $type) }}</option>
                    @endforeach
                </select>
                <button class="pl-btn-primary justify-center" type="submit">
                    <i data-lucide="filter" class="h-4 w-4"></i>
                    <span>Filter</span>
                </button>
                <a href="{{ route('app.agentic-marketing.approvals.index') }}" class="pl-btn-ghost justify-center">Reset</a>
            </form>
        </section>

        <div class="space-y-4">
            @forelse ($runs as $run)
                <x-approvals.conversation-card :run="$run" :service="$approvalRecommendationService" />
            @empty
                <div class="rounded-lg border border-border bg-surface px-5 py-10 text-center">
                    <p class="text-sm font-medium text-textPrimary">No approval conversations needed</p>
                    <p class="mt-1 text-sm text-textSecondary">When Argusly needs approval, judgment, or setup input, the conversation will appear here.</p>
                </div>
            @endforelse

            @if ($runs->hasPages())
                <div class="rounded-lg border border-border bg-surface px-5 py-4">{{ $runs->links() }}</div>
            @endif
        </div>
    </div>
@endsection
