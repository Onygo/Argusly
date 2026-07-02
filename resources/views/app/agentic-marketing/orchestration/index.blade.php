@extends('layouts.app', ['title' => 'Agent Orchestration'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Agent Orchestration</x-slot:title>
        <x-slot:description>Coordinate specialized Agentic Marketing agents with shared context, memory, task delegation, normalized results, conflicts, and execution traces.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <form method="POST" action="{{ route('app.agentic-marketing.orchestration.run') }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
                <input name="focus_topic" value="{{ old('focus_topic', request('focus_topic')) }}" class="pl-input w-48 bg-background text-sm" placeholder="Focus topic">
                <label class="inline-flex items-center gap-2 text-xs text-textSecondary">
                    <input type="checkbox" name="run_inline" value="1">
                    Run now
                </label>
                <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Run agents</button>
            </form>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <div class="rounded-lg border border-border bg-surface p-4">
            <form method="GET" action="{{ route('app.agentic-marketing.orchestration.index') }}" class="grid gap-3 md:grid-cols-4">
                <select name="workspace_id" class="pl-select bg-background" onchange="this.form.submit()">
                    @foreach ($workspaces as $item)
                        <option value="{{ $item->id }}" @selected((string) $item->id === (string) $workspace->id)>{{ $item->display_name }}</option>
                    @endforeach
                </select>
                <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Filter</button>
            </form>
        </div>

        <section class="grid gap-3 md:grid-cols-4">
            @foreach ($agents as $agent)
                <div class="rounded-lg border border-border bg-surface p-4">
                    <p class="text-sm font-semibold text-textPrimary">{{ $agent['name'] }}</p>
                    <p class="mt-1 text-xs text-textSecondary">{{ $agent['role'] }}</p>
                    <div class="mt-3 flex flex-wrap gap-1">
                        @foreach ($agent['capabilities'] as $capability)
                            <span class="rounded-full border border-border px-2 py-0.5 text-[11px] text-textSecondary">{{ str_replace('_', ' ', $capability) }}</span>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold text-textPrimary">Recent orchestration runs</h2>
            </div>
            <div class="divide-y divide-border">
                @forelse ($runs as $run)
                    @php
                        $focusTopic = data_get($run->shared_context, 'focus.topic')
                            ?: data_get($run->input, 'focus_topic')
                            ?: data_get($run->shared_context, 'company.primary_topics.0');
                    @endphp
                    <article class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xs text-textSecondary">{{ $run->workflow_key }} · {{ strtoupper($run->status) }}</p>
                                <h3 class="mt-1 text-sm font-semibold text-textPrimary">
                                    <a href="{{ route('app.agentic-marketing.orchestration.show', $run) }}" class="hover:text-primary">Run {{ $run->created_at?->format('Y-m-d H:i') }}</a>
                                </h3>
                                <p class="mt-2 text-sm text-textPrimary">
                                    Focus: <span class="font-medium">{{ filled($focusTopic) ? $focusTopic : 'Not set' }}</span>
                                </p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $run->tasks_count }} tasks · {{ $run->completed_tasks_count }} completed · {{ $run->conflicts_count }} conflicts</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-textSecondary">Confidence</p>
                                <p class="text-xl font-semibold text-textPrimary">{{ number_format((float) $run->confidence_score, 1) }}</p>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="px-5 py-8 text-sm text-textSecondary">No orchestration runs yet.</div>
                @endforelse
            </div>
        </section>

        <div>{{ $runs->links() }}</div>
    </div>
@endsection
