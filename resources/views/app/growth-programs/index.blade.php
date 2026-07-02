@extends('layouts.app', ['title' => 'Growth Programs'])

@section('pageHeader')
    <x-page-header title="Growth Programs">
        <x-slot:description>Plan and manage growth programs generated from opportunities and strategy signals.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-textPrimary">Growth Programs</h2>
                <p class="mt-1 max-w-3xl text-textSecondary">Track growth initiatives across opportunity intelligence, execution planning, briefing, drafting, publishing, and measurement.</p>
            </div>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Programs</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $summary['total'] }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Active</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $summary['active'] }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Published</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $summary['published'] }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Avg score</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $summary['avg_score'], 1) }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-4">
            <form method="GET" action="{{ route('app.growth-programs.index') }}" class="grid gap-3 md:grid-cols-4">
                <select name="workspace_id" class="pl-select bg-background" onchange="this.form.submit()">
                    @foreach ($workspaces as $item)
                        <option value="{{ $item->id }}" @selected((string) $item->id === (string) $workspace->id)>{{ $item->display_name }}</option>
                    @endforeach
                </select>
                <select name="status" class="pl-select bg-background">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Filter</button>
                <a href="{{ route('app.agentic-marketing.intelligence.index', ['workspace_id' => $workspace->id]) }}" class="rounded-md border border-border bg-background px-3 py-2 text-center text-sm text-textPrimary">Open opportunities</a>
            </form>
        </div>

        <div class="space-y-4">
            @forelse ($programs as $program)
                @php
                    $metrics = (array) ($program->metrics ?? []);
                    $status = $program->status instanceof \App\Enums\GrowthProgramStatus ? $program->status : \App\Enums\GrowthProgramStatus::tryFrom((string) $program->status);
                    $progress = $program->progress();
                @endphp
                <article class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-xs text-textSecondary">{{ $status?->label() ?? $program->status }} · {{ $program->owner?->name ?: 'No owner' }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-textPrimary">
                                <a href="{{ route('app.growth-programs.show', $program) }}" class="hover:text-primary">{{ $program->name }}</a>
                            </h2>
                            @if ($program->description)
                                <p class="mt-2 max-w-3xl text-sm text-textSecondary">{{ $program->description }}</p>
                            @endif
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-textSecondary">Impact score</p>
                            <p class="text-xl font-semibold text-textPrimary">{{ number_format((float) $program->score, 1) }}</p>
                        </div>
                    </div>

                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-background">
                        <div class="h-full rounded-full bg-primary" style="width: {{ $progress }}%"></div>
                    </div>

                    <div class="mt-4 grid gap-3 text-xs text-textSecondary md:grid-cols-5">
                        <div>Opportunities: <span class="text-textPrimary">{{ (int) ($metrics['opportunities_count'] ?? 0) }}</span></div>
                        <div>Assets: <span class="text-textPrimary">{{ $program->assets_count }}</span></div>
                        <div>Runs: <span class="text-textPrimary">{{ $program->runs_count }}</span></div>
                        <div>Reach: <span class="text-textPrimary">{{ number_format((float) ($metrics['estimated_reach'] ?? $program->estimated_reach), 0) }}</span></div>
                        <div>AI visibility: <span class="text-textPrimary">{{ number_format((float) ($metrics['estimated_ai_visibility'] ?? $program->estimated_ai_visibility_impact), 1) }}</span></div>
                    </div>
                </article>
            @empty
                <div class="rounded-lg border border-border bg-surface p-6 text-sm text-textSecondary">No growth programs yet. Create one from an approved opportunity to start central orchestration.</div>
            @endforelse

            <div>{{ $programs->links() }}</div>
        </div>
    </div>
@endsection
