@extends('layouts.app', ['title' => $report->title])

@php
    $sections = $payload['sections'] ?? [];
    $sectionLabels = [
        'executive_summary' => 'Executive Summary',
        'top_opportunities' => 'Top Opportunities',
        'top_risks' => 'Top Risks',
        'competitor_movements' => 'Competitor Movements',
        'serp_movements' => 'SERP Movements',
        'geo_ai_visibility_movements' => 'GEO/AI Visibility Movements',
        'highest_pr_value_pages' => 'Highest PR Value Pages',
        'recommended_actions' => 'Recommended Actions',
        'evidence_links' => 'Evidence Links',
        'market_pack_summary' => 'Market Pack Summary',
        'campaign_impact' => 'Campaign Impact',
    ];
@endphp

@section('pageHeader')
    <x-page-header :title="$report->title">
        <x-slot:description>{{ $payload['label'] ?? str($report->report_type)->headline() }} · snapshot v{{ $report->snapshot_version }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('app.page-intelligence.reports.index', ['workspace' => $report->workspace_id]) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Reports</a>
    <a href="{{ route('app.page-intelligence.reports.export', $report) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Export layout</a>
    @if ($report->artifact_status === \App\Models\PageIntelligenceReport::ARTIFACT_STATUS_READY)
        <a href="{{ route('app.page-intelligence.reports.artifact.download', $report) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Download PDF</a>
    @else
        <form method="POST" action="{{ route('app.page-intelligence.reports.artifact.generate', $report) }}">
            @csrf
            <button @disabled($report->artifact_status === \App\Models\PageIntelligenceReport::ARTIFACT_STATUS_GENERATING) class="rounded-md border border-textPrimary bg-textPrimary px-3 py-2 text-sm text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">
                {{ $report->artifact_status === \App\Models\PageIntelligenceReport::ARTIFACT_STATUS_GENERATING ? 'Generating PDF' : 'Generate PDF' }}
            </button>
        </form>
    @endif
@endsection

@section('content')
    <style>
        @media print {
            nav, aside, form, button, [data-app-sidebar], [data-topbar] { display: none !important; }
            main { max-width: 100% !important; }
            section { break-inside: avoid; }
        }
    </style>

    <div class="{{ $export ? 'mx-auto max-w-4xl bg-white p-8 text-slate-950 shadow-sm' : 'space-y-6' }}">
        <section class="rounded-lg border border-border bg-surface p-6">
            <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-5">
                <div>
                    <p class="text-xs text-textSecondary">Type</p>
                    <p class="text-sm font-medium text-textPrimary">{{ $payload['label'] ?? str($report->report_type)->headline() }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Period</p>
                    <p class="text-sm font-medium text-textPrimary">{{ $report->period_start?->toDateString() }} to {{ $report->period_end?->toDateString() }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Market</p>
                    <p class="text-sm font-medium text-textPrimary">{{ data_get($payload, 'market_pack.name') ?: 'All markets' }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Provenance</p>
                    <p class="text-sm font-medium text-textPrimary">{{ data_get($payload, 'provenance.generated_from_existing_data_only') ? 'Existing data only' : 'Unknown' }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Artifact</p>
                    <p class="text-sm font-medium text-textPrimary">{{ str($report->artifact_status ?: 'pending')->headline() }}</p>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Delivery Status</h2>
                    <p class="mt-1 text-sm text-textSecondary">{{ $report->deliveries->count() }} delivery record{{ $report->deliveries->count() === 1 ? '' : 's' }}</p>
                </div>
                @if ($report->scheduledBriefing)
                    <a href="{{ route('app.page-intelligence.scheduled-briefings.edit', $report->scheduledBriefing) }}" class="text-sm text-textPrimary hover:underline">Scheduled briefing</a>
                @endif
            </div>
            <div class="mt-4 divide-y divide-border">
                @forelse ($report->deliveries as $delivery)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <p class="text-sm font-medium text-textPrimary">
                                {{ $delivery->recipientUser?->name ?: $delivery->recipient_email ?: 'Recipient placeholder' }}
                            </p>
                            <p class="mt-1 text-xs text-textSecondary">
                                {{ str($delivery->channel)->headline() }} · {{ str($delivery->status)->headline() }}
                                @if ($delivery->delivered_at)
                                    · {{ $delivery->delivered_at->format('M j, Y H:i') }}
                                @elseif ($delivery->failed_at)
                                    · {{ $delivery->failed_at->format('M j, Y H:i') }}
                                @endif
                            </p>
                            @if ($delivery->error)
                                <p class="mt-1 text-xs text-textSecondary">{{ $delivery->error }}</p>
                            @endif
                        </div>
                        <span class="rounded border border-border px-2 py-1 text-xs text-textSecondary">{{ str($delivery->status)->headline() }}</span>
                    </div>
                @empty
                    <p class="text-sm text-textSecondary">No delivery records yet.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface p-6">
            <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Executive Summary</p>
            <h2 class="mt-2 text-xl font-semibold text-textPrimary">{{ data_get($sections, 'executive_summary.headline') }}</h2>
            <p class="mt-2 text-sm text-textSecondary">{{ data_get($sections, 'executive_summary.narrative') }}</p>
            <div class="mt-4 grid gap-3 md:grid-cols-4">
                @foreach ((array) data_get($sections, 'executive_summary.metrics', []) as $label => $value)
                    <div class="rounded-md border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">{{ str($label)->headline() }}</p>
                        <p class="mt-1 text-lg font-semibold text-textPrimary">{{ number_format((float) $value) }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        @foreach (['top_opportunities', 'top_risks', 'competitor_movements', 'serp_movements', 'geo_ai_visibility_movements', 'highest_pr_value_pages', 'recommended_actions', 'market_pack_summary', 'campaign_impact'] as $sectionKey)
            @php($section = $sections[$sectionKey] ?? [])
            <section class="rounded-lg border border-border bg-surface p-6 {{ $export ? 'mt-6' : '' }}">
                <h2 class="text-base font-semibold text-textPrimary">{{ $sectionLabels[$sectionKey] }}</h2>

                @if ($sectionKey === 'market_pack_summary')
                    <p class="mt-2 text-sm text-textSecondary">{{ $section['summary'] ?? 'No market pack summary available.' }}</p>
                    @if (! empty($section['themes']) || ! empty($section['competitors']))
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <div>
                                <p class="text-xs font-medium text-textSecondary">Themes</p>
                                <p class="mt-1 text-sm text-textPrimary">{{ collect($section['themes'] ?? [])->implode(', ') ?: '-' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-textSecondary">Competitors</p>
                                <p class="mt-1 text-sm text-textPrimary">{{ collect($section['competitors'] ?? [])->implode(', ') ?: '-' }}</p>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="mt-4 divide-y divide-border">
                        @forelse ((array) $section as $row)
                            <article class="py-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-medium text-textPrimary">{{ $row['title'] ?? $row['page'] ?? 'Report item' }}</h3>
                                        <p class="mt-1 text-sm text-textSecondary">{{ $row['summary'] ?? $row['rationale'] ?? $row['recommended_action'] ?? '' }}</p>
                                    </div>
                                    @if (isset($row['score']) || isset($row['visibility_score']) || isset($row['match_score']))
                                        <span class="rounded border border-border px-2 py-1 text-xs text-textSecondary">
                                            {{ number_format((float) ($row['score'] ?? $row['visibility_score'] ?? $row['match_score']), 1) }}
                                        </span>
                                    @endif
                                </div>
                                @if (! empty($row['evidence']['url']))
                                    <a href="{{ $row['evidence']['url'] }}" class="mt-2 inline-block break-all text-xs text-textPrimary hover:underline">{{ $row['evidence']['label'] ?? 'Evidence page' }}</a>
                                @endif
                            </article>
                        @empty
                            <p class="mt-3 text-sm text-textSecondary">No items for this section.</p>
                        @endforelse
                    </div>
                @endif
            </section>
        @endforeach

        <section class="rounded-lg border border-border bg-surface p-6 {{ $export ? 'mt-6' : '' }}">
            <h2 class="text-base font-semibold text-textPrimary">Evidence Links</h2>
            <div class="mt-4 divide-y divide-border">
                @forelse (($payload['evidence_links'] ?? []) as $link)
                    <div class="py-3">
                        <a href="{{ $link['url'] }}" class="break-all text-sm font-medium text-textPrimary hover:underline">{{ $link['label'] }}</a>
                        <p class="mt-1 break-all text-xs text-textSecondary">{{ $link['canonical_url'] ?? '' }}</p>
                    </div>
                @empty
                    <p class="text-sm text-textSecondary">No evidence links captured.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface p-6 {{ $export ? 'mt-6' : '' }}">
            <h2 class="text-base font-semibold text-textPrimary">Data Provenance</h2>
            <p class="mt-2 text-sm text-textSecondary">Template {{ data_get($payload, 'provenance.template_version') }} · direct fetching {{ data_get($payload, 'provenance.direct_fetching') ? 'enabled' : 'disabled' }}</p>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                @foreach ((array) data_get($payload, 'provenance.source_tables', []) as $table => $count)
                    <div class="rounded-md border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">{{ $table }}</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">{{ number_format((float) $count) }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
@endsection
