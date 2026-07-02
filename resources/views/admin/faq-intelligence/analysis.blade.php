@extends('layouts.admin', ['title' => 'FAQ Intelligence analysis'])

@section('pageHeader')
    <x-page-header title="FAQ analysis">
        <x-slot:description>{{ data_get($result, 'page.title') ?: data_get($result, 'page.page_slug') }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('admin.faq-intelligence.index') }}" class="pl-btn-secondary">Back</a>
@endsection

@section('metricSection')
    <x-metric-section>
        @foreach (data_get($result, 'scores', []) as $key => $score)
            <x-metric-card :label="str($key)->replace('_', ' ')->title()" :value="number_format((float) ($score['score'] ?? 0), 1)">
                {{ $score['rationale'] ?? '' }}
            </x-metric-card>
        @endforeach
    </x-metric-section>
@endsection

@section('content')
    <div class="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-sm font-semibold text-textPrimary">Missing questions</h2>
            <div class="mt-4 space-y-4">
                @foreach (data_get($result, 'detected_gaps', []) as $group => $questions)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-textSecondary">{{ str($group)->replace('_', ' ')->title() }}</p>
                        <ul class="mt-2 space-y-2 text-sm text-textSecondary">
                            @forelse ((array) $questions as $question)
                                <li class="rounded border border-border bg-background px-3 py-2">{{ $question }}</li>
                            @empty
                                <li class="text-xs text-textFaint">No gap detected.</li>
                            @endforelse
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">Generated FAQ's</h2>
                    <p class="mt-1 text-sm text-textSecondary">Review the generated candidates before publishing.</p>
                </div>
                <form method="POST" action="{{ route('admin.faq-intelligence.publish') }}">
                    @csrf
                    @foreach ($input as $key => $value)
                        @if (is_array($value))
                            <input type="hidden" name="{{ $key }}" value="{{ implode("\n", $value) }}">
                        @else
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <input type="hidden" name="generated_faqs" value="{{ json_encode(data_get($result, 'recommended_faqs', []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}">
                    <button class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white">Publish FAQ's</button>
                </form>
            </div>

            <div class="mt-5 space-y-4">
                @forelse (data_get($result, 'recommended_faqs', []) as $faq)
                    <article class="rounded border border-border bg-background p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <h3 class="max-w-2xl text-sm font-semibold text-textPrimary">{{ $faq['question'] }}</h3>
                            <span class="rounded border border-border px-2 py-0.5 text-[11px] text-textSecondary">Priority {{ $faq['priority'] }}</span>
                        </div>
                        <p class="mt-3 text-sm leading-7 text-textSecondary">{{ $faq['answer'] }}</p>
	                        <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-textSecondary">
	                            <span class="rounded border border-border px-2 py-0.5">{{ $faq['faq_type'] }}</span>
	                            <span class="rounded border border-border px-2 py-0.5">{{ $faq['search_intent'] }}</span>
	                            <span class="rounded border border-border px-2 py-0.5">{{ $faq['funnel_stage'] }}</span>
	                            <span class="rounded border border-border px-2 py-0.5">CTA: {{ $faq['suggested_cta'] }}</span>
	                        </div>
	                        <form method="POST" action="{{ route('admin.faq-intelligence.accept') }}" class="mt-4">
	                            @csrf
	                            @foreach ($input as $key => $value)
	                                @if (is_array($value))
	                                    <input type="hidden" name="{{ $key }}" value="{{ implode("\n", $value) }}">
	                                @else
	                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
	                                @endif
	                            @endforeach
	                            <input type="hidden" name="generated_faq" value="{{ json_encode($faq, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}">
	                            <button class="rounded-md border border-border px-3 py-2 text-xs font-semibold text-textPrimary hover:bg-surface">Accept proposal</button>
	                        </form>
	                    </article>
                @empty
                    <p class="text-sm text-textSecondary">No new FAQ's were generated. Existing coverage may already be sufficient.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-sm font-semibold text-textPrimary">FAQPage schema</h2>
            @if (data_get($result, 'schema_validation_errors'))
                <div class="mt-3 rounded border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800">
                    {{ implode(' ', data_get($result, 'schema_validation_errors', [])) }}
                </div>
            @endif
            <pre class="mt-4 max-h-96 overflow-auto rounded border border-border bg-background p-3 text-xs text-textSecondary">{{ json_encode(data_get($result, 'faq_schema'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-sm font-semibold text-textPrimary">Internal links and CTA's</h2>
            <div class="mt-4">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-textSecondary">Links</p>
                <ul class="mt-2 space-y-2 text-sm text-textSecondary">
                    @foreach (data_get($result, 'internal_link_opportunities', []) as $link)
                        <li class="rounded border border-border bg-background px-3 py-2">{{ $link['label'] ?? '' }} <span class="text-textFaint">{{ $link['route'] ?? '' }}</span></li>
                    @endforeach
                </ul>
            </div>
            <div class="mt-5">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-textSecondary">CTA's</p>
                <ul class="mt-2 space-y-2 text-sm text-textSecondary">
                    @foreach (data_get($result, 'suggested_ctas', []) as $cta)
                        <li class="rounded border border-border bg-background px-3 py-2">{{ $cta }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endsection
