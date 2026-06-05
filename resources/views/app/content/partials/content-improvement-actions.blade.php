@php
    $activeByType = (array) ($contentImprovementDashboard['active_by_type'] ?? []);
@endphp

<div id="content-improvement-actions" class="mt-3 grid gap-3 lg:grid-cols-2">
    @forelse (($contentImprovementOptions ?? []) as $option)
        @php
            $activeState = $activeByType[(string) ($option['type'] ?? '')] ?? null;
            $state = (string) ($option['state'] ?? 'generate');
            $hasGeneratedDraft = filled($option['target_draft_id'] ?? null);
        @endphp
        <form method="POST" action="{{ route('app.content.improvements.queue', $content) }}" class="flex items-center justify-between gap-3 rounded-2xl border border-border/70 bg-slate-50 px-4 py-3" data-content-improvement-form>
            @csrf
            <input type="hidden" name="type" value="{{ $option['type'] }}">
            <input type="hidden" name="recommendation" value="{{ $option['description'] }}">
            <div>
                <div class="text-sm font-medium text-textPrimary">{{ $option['label'] }}</div>
                <div class="mt-1 text-xs text-textSecondary">{{ $option['score_hint'] }}</div>
                <div class="mt-1 text-xs text-textSecondary">{{ $option['description'] }}</div>
                @if ($state === 'generated')
                    <div class="mt-2 text-xs text-emerald-700">
                        Generated for this revision.
                        @if (filled($option['latest_summary'] ?? null))
                            {{ $option['latest_summary'] }}
                        @endif
                    </div>
                @elseif ($state === 'no_changes')
                    <div class="mt-2 text-xs text-amber-700">
                        No useful changes were generated for this revision.
                    </div>
                @endif
            </div>
            <div class="flex flex-col items-end gap-2">
                @if ($state === 'generated' && $hasGeneratedDraft)
                    <a
                        href="{{ route('app.drafts.show', ['draft' => $option['target_draft_id']]) }}"
                        class="inline-flex items-center justify-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800 hover:bg-emerald-100"
                    >
                        Review draft
                    </a>
                @endif
                <button
                    type="submit"
                    class="inline-flex items-center justify-center gap-2 rounded-full border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-white disabled:cursor-not-allowed disabled:opacity-60"
                    data-content-improvement-button
                    data-improvement-type="{{ $option['type'] }}"
                    @disabled($activeState !== null || $state === 'generated')
                >
                    @if ($activeState === 'queued')
                        <span class="h-2 w-2 animate-pulse rounded-full bg-sky-500"></span>
                        Queued
                    @elseif ($activeState === 'running')
                        <span class="h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>
                        Running
                    @else
                        {{ $option['state_label'] ?? 'Generate' }}
                    @endif
                </button>
            </div>
        </form>
    @empty
        <div class="rounded-2xl bg-slate-50 px-4 py-5 text-sm text-textSecondary">
            No AI improvement recommendations available yet.
        </div>
    @endforelse
</div>
