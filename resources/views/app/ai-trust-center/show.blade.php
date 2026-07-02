@extends('layouts.app', ['title' => 'AI Trust Center'])

@php
    $badgeTone = match ($record->origin) {
        'human' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'ai_assisted' => 'border-sky-200 bg-sky-50 text-sky-800',
        'ai_generated', 'ai_edited' => 'border-violet-200 bg-violet-50 text-violet-800',
        default => 'border-slate-200 bg-slate-50 text-slate-700',
    };
    $scoreTone = $record->trust_score >= 80
        ? 'text-emerald-700'
        : ($record->trust_score >= 55 ? 'text-amber-700' : 'text-rose-700');
@endphp

@section('pageHeader')
    <x-page-header :title="'AI Trust Center'" eyebrow="Transparency & Provenance" />
@endsection

@section('pageDescription')
    <x-page-description>{{ $content->title }}</x-page-description>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="AI Badge" :value="$record->ai_badge" :helper="$record->origin" />
        <x-metric-card label="Trust Score" :value="$record->trust_score . '/100'" :helper="$record->fact_check_status" />
        <x-metric-card label="Human Review" :value="str_replace('_', ' ', $record->human_review_status)" />
        <x-metric-card label="Model Runs" :value="count($payload['model_history'])" />
    </x-metric-section>
@endsection

@section('content')
    @if(session('status'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('app.content.show', $content) }}" class="inline-flex items-center rounded-lg border border-border bg-white px-3 py-2 text-sm font-medium text-text hover:bg-surfaceSubtle">
                Back to content
            </a>
            <a href="{{ route('app.content.ai-trust.audit-report', $content) }}" class="inline-flex items-center rounded-lg bg-text px-3 py-2 text-sm font-semibold text-white hover:bg-text/90">
                Download audit PDF
            </a>
        </div>
        <span class="inline-flex items-center rounded-full border px-3 py-1 text-sm font-semibold {{ $badgeTone }}">
            {{ $record->ai_badge }}
        </span>
    </div>

    <section class="mb-6 rounded-xl border border-border bg-white p-5">
        <div class="grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
            <div>
                <h2 class="text-lg font-semibold text-text">Disclosure</h2>
                <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $record->disclosure_label }}</p>
                <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-border bg-surfaceSubtle p-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Content hash</dt>
                        <dd class="mt-1 break-all font-mono text-xs text-text">{{ $record->content_hash }}</dd>
                    </div>
                    <div class="rounded-lg border border-border bg-surfaceSubtle p-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Metadata standard</dt>
                        <dd class="mt-1 text-sm text-text">{{ $record->metadata_standard }}</dd>
                    </div>
                </dl>
            </div>
            <form method="POST" action="{{ route('app.content.ai-trust.disclosure', $content) }}" class="rounded-lg border border-border bg-surfaceSubtle p-4">
                @csrf
                <label for="origin" class="block text-sm font-semibold text-text">AI origin</label>
                <select id="origin" name="origin" class="mt-2 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm">
                    @foreach(['unknown' => 'Unknown', 'human' => 'Human', 'ai_assisted' => 'AI-assisted', 'ai_generated' => 'AI-generated', 'ai_edited' => 'AI-edited'] as $value => $label)
                        <option value="{{ $value }}" @selected($record->origin === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="mt-3 rounded-lg bg-text px-3 py-2 text-sm font-semibold text-white">Update disclosure</button>
            </form>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <section class="rounded-xl border border-border bg-white p-5">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-text">Provenance Timeline</h2>
                <span class="text-sm text-textSecondary">{{ count($payload['timeline']) }} events</span>
            </div>
            <div class="space-y-4">
                @forelse($payload['timeline'] as $event)
                    <div class="border-l-2 border-border pl-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-text">{{ str_replace('_', ' ', $event['type']) }}</span>
                            <span class="text-xs text-textSecondary">{{ $event['occurred_at'] }}</span>
                        </div>
                        <p class="mt-1 text-sm text-textSecondary">{{ $event['summary'] ?? 'No summary recorded.' }}</p>
                        @if($event['output_hash'])
                            <p class="mt-1 break-all font-mono text-xs text-textSecondary">{{ $event['output_hash'] }}</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-textSecondary">No provenance events recorded yet.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl border border-border bg-white p-5">
            <h2 class="text-lg font-semibold text-text">Trust Score</h2>
            <div class="mt-3 text-5xl font-semibold {{ $scoreTone }}">{{ $record->trust_score }}</div>
            <div class="mt-4 space-y-2">
                @foreach(($record->score_breakdown ?? []) as $label => $value)
                    <div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="capitalize text-textSecondary">{{ str_replace('_', ' ', $label) }}</span>
                            <span class="font-medium text-text">{{ $value }}</span>
                        </div>
                        <div class="mt-1 h-2 rounded-full bg-slate-100">
                            <div class="h-2 rounded-full bg-text" style="width: {{ min(100, (int) $value * 4) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="rounded-xl border border-border bg-white p-5">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-text">Model History</h2>
                <span class="text-sm text-textSecondary">{{ count($payload['model_history']) }} runs</span>
            </div>
            <div class="space-y-3">
                @forelse($payload['model_history'] as $run)
                    <div class="rounded-lg border border-border bg-surfaceSubtle p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="font-medium text-text">{{ $run['model'] ?: 'Unknown model' }}</div>
                            <div class="text-xs text-textSecondary">{{ $run['ran_at'] }}</div>
                        </div>
                        <div class="mt-1 text-sm text-textSecondary">{{ $run['provider'] ?: 'Unknown provider' }} @if($run['run_id']) · {{ $run['run_id'] }} @endif</div>
                        @if(! empty($run['usage']))
                            <pre class="mt-2 overflow-x-auto rounded bg-white p-2 text-xs text-textSecondary">{{ json_encode($run['usage'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-textSecondary">No model runs recorded yet.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl border border-border bg-white p-5">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-text">Prompt History</h2>
                <span class="text-sm text-textSecondary">{{ count($payload['prompt_history']) }} versions</span>
            </div>
            <div class="space-y-3">
                @forelse($payload['prompt_history'] as $prompt)
                    <div class="rounded-lg border border-border bg-surfaceSubtle p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="font-medium capitalize text-text">{{ str_replace('_', ' ', $prompt['prompt_type']) }} v{{ $prompt['version'] }}</div>
                            <div class="text-xs text-textSecondary">{{ $prompt['captured_at'] }}</div>
                        </div>
                        @if($prompt['summary'])
                            <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $prompt['summary'] }}</p>
                        @endif
                        @if($prompt['prompt_hash'])
                            <p class="mt-2 break-all font-mono text-xs text-textSecondary">{{ $prompt['prompt_hash'] }}</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-textSecondary">No prompt versions recorded yet.</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="rounded-xl border border-border bg-white p-5">
            <h2 class="text-lg font-semibold text-text">Human Review</h2>
            <form method="POST" action="{{ route('app.content.ai-trust.review', $content) }}" class="mt-4 space-y-3">
                @csrf
                <select name="status" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-sm">
                    @foreach(['reviewed' => 'Reviewed', 'approved' => 'Approved', 'needs_changes' => 'Needs changes', 'rejected' => 'Rejected'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <div class="grid gap-2 text-sm text-textSecondary sm:grid-cols-3">
                    <label><input type="checkbox" name="checklist[provenance_checked]" value="1" class="mr-1"> Provenance</label>
                    <label><input type="checkbox" name="checklist[sources_checked]" value="1" class="mr-1"> Sources</label>
                    <label><input type="checkbox" name="checklist[disclosure_checked]" value="1" class="mr-1"> Disclosure</label>
                </div>
                <textarea name="notes" rows="3" class="w-full rounded-lg border border-border px-3 py-2 text-sm" placeholder="Review notes"></textarea>
                <button class="rounded-lg bg-text px-3 py-2 text-sm font-semibold text-white">Save review</button>
            </form>
        </section>

        <section class="rounded-xl border border-border bg-white p-5">
            <h2 class="text-lg font-semibold text-text">Fact-check Status</h2>
            <form method="POST" action="{{ route('app.content.ai-trust.fact-check', $content) }}" class="mt-4 space-y-3">
                @csrf
                <textarea name="claim" rows="2" class="w-full rounded-lg border border-border px-3 py-2 text-sm" placeholder="Claim to verify"></textarea>
                <div class="grid gap-3 sm:grid-cols-[1fr_120px]">
                    <select name="status" class="rounded-lg border border-border bg-white px-3 py-2 text-sm">
                        @foreach(['unchecked' => 'Unchecked', 'supported' => 'Supported', 'partial' => 'Partial', 'conflicting' => 'Conflicting', 'needs_human_review' => 'Needs review'] as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input name="confidence" type="number" min="0" max="100" class="rounded-lg border border-border px-3 py-2 text-sm" placeholder="0-100">
                </div>
                <input name="evidence_url" type="url" class="w-full rounded-lg border border-border px-3 py-2 text-sm" placeholder="Evidence URL">
                <textarea name="notes" rows="2" class="w-full rounded-lg border border-border px-3 py-2 text-sm" placeholder="Evidence or notes"></textarea>
                <button class="rounded-lg bg-text px-3 py-2 text-sm font-semibold text-white">Save fact-check</button>
            </form>
        </section>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
        <section class="rounded-xl border border-border bg-white p-5">
            <h2 class="text-lg font-semibold text-text">Source Trace</h2>
            <form method="POST" action="{{ route('app.content.ai-trust.source-trace', $content) }}" class="mt-4 space-y-3">
                @csrf
                <div class="grid gap-3 sm:grid-cols-2">
                    <select name="source_type" class="rounded-lg border border-border bg-white px-3 py-2 text-sm">
                        @foreach(['url' => 'URL', 'document' => 'Document', 'dataset' => 'Dataset', 'internal' => 'Internal', 'interview' => 'Interview', 'other' => 'Other'] as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="retrieval_status" class="rounded-lg border border-border bg-white px-3 py-2 text-sm">
                        @foreach(['available' => 'Available', 'archived' => 'Archived', 'unavailable' => 'Unavailable', 'needs_review' => 'Needs review'] as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <input name="title" class="w-full rounded-lg border border-border px-3 py-2 text-sm" placeholder="Source title">
                <input name="url" type="url" class="w-full rounded-lg border border-border px-3 py-2 text-sm" placeholder="https://example.com/source">
                <div class="grid gap-3 sm:grid-cols-[120px_1fr]">
                    <input name="reliability_score" type="number" min="0" max="100" class="rounded-lg border border-border px-3 py-2 text-sm" placeholder="0-100">
                    <input name="used_for_sections" class="rounded-lg border border-border px-3 py-2 text-sm" placeholder="Sections, comma separated">
                </div>
                <textarea name="notes" rows="2" class="w-full rounded-lg border border-border px-3 py-2 text-sm" placeholder="Notes"></textarea>
                <button class="rounded-lg bg-text px-3 py-2 text-sm font-semibold text-white">Save source</button>
            </form>
        </section>

        <section class="rounded-xl border border-border bg-white p-5">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-text">Recorded Sources</h2>
                <span class="text-sm text-textSecondary">{{ count($payload['source_trace']) }} sources</span>
            </div>
            <div class="space-y-3">
                @forelse($payload['source_trace'] as $source)
                    <div class="rounded-lg border border-border bg-surfaceSubtle p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="font-medium text-text">{{ $source['title'] ?: ucfirst($source['source_type']) }}</div>
                            <div class="text-xs text-textSecondary">{{ $source['retrieval_status'] }}</div>
                        </div>
                        @if($source['url'])
                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="mt-1 block break-all text-sm text-link hover:text-linkHover">{{ $source['url'] }}</a>
                        @endif
                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-textSecondary">
                            @if($source['reliability_score'] !== null)
                                <span>Reliability {{ $source['reliability_score'] }}/100</span>
                            @endif
                            @foreach(($source['used_for_sections'] ?? []) as $section)
                                <span class="rounded-full border border-border bg-white px-2 py-1">{{ $section }}</span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-textSecondary">No source traces recorded yet.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="mt-6 rounded-xl border border-border bg-white p-5">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-text">Fact-check Log</h2>
            <span class="text-sm text-textSecondary">{{ count($payload['fact_checks']) }} checks</span>
        </div>
        <div class="space-y-3">
            @forelse($payload['fact_checks'] as $factCheck)
                <div class="rounded-lg border border-border bg-surfaceSubtle p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="font-medium text-text">{{ $factCheck['status'] }}</div>
                        <div class="text-xs text-textSecondary">{{ $factCheck['reviewed_at'] }}</div>
                    </div>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $factCheck['claim'] }}</p>
                    @if($factCheck['confidence'] !== null)
                        <p class="mt-1 text-xs text-textSecondary">Confidence {{ $factCheck['confidence'] }}/100</p>
                    @endif
                </div>
            @empty
                <p class="text-sm text-textSecondary">No fact-checks recorded yet.</p>
            @endforelse
        </div>
    </section>

    <section class="mt-6 rounded-xl border border-border bg-white p-5">
        <h2 class="text-lg font-semibold text-text">Machine-readable Metadata</h2>
        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs leading-5 text-slate-100">{{ json_encode($payload['record']['machine_metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </section>
@endsection
