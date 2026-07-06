@extends('layouts.export-pdf', ['title' => $report->title])

@php
    $sections = $payload['sections'] ?? [];
    $sectionLabels = [
        'top_opportunities' => 'Top Opportunities',
        'top_risks' => 'Top Risks',
        'competitor_movements' => 'Competitor Movements',
        'serp_movements' => 'SERP Movements',
        'geo_ai_visibility_movements' => 'GEO/AI Visibility Movements',
        'highest_pr_value_pages' => 'Highest PR Value Pages',
        'recommended_actions' => 'Recommended Actions',
        'market_pack_summary' => 'Market Pack Summary',
        'campaign_impact' => 'Campaign Impact',
    ];
@endphp

@section('content')
    <header>
        <p class="label">{{ $payload['label'] ?? str($report->report_type)->headline() }} · snapshot v{{ $report->snapshot_version }}</p>
        <h1>{{ $report->title }}</h1>
        <p class="muted">{{ $report->period_start?->toDateString() }} to {{ $report->period_end?->toDateString() }} · {{ data_get($payload, 'market_pack.name') ?: 'All markets' }}</p>
    </header>

    <section>
        <h2>Executive Summary</h2>
        <p>{{ data_get($sections, 'executive_summary.narrative') }}</p>
        <div class="grid" style="margin-top: 12px;">
            @foreach ((array) data_get($sections, 'executive_summary.metrics', []) as $label => $value)
                <div class="metric">
                    <p class="label">{{ str($label)->headline() }}</p>
                    <p class="value">{{ number_format((float) $value) }}</p>
                </div>
            @endforeach
        </div>
    </section>

    @foreach ($sectionLabels as $sectionKey => $label)
        @php($section = $sections[$sectionKey] ?? [])
        <section>
            <h2>{{ $label }}</h2>
            @if ($sectionKey === 'market_pack_summary')
                <p>{{ $section['summary'] ?? 'No market pack summary available.' }}</p>
                @if (! empty($section['themes']) || ! empty($section['competitors']))
                    <p class="muted" style="margin-top: 8px;">Themes: {{ collect($section['themes'] ?? [])->implode(', ') ?: '-' }}</p>
                    <p class="muted">Competitors: {{ collect($section['competitors'] ?? [])->implode(', ') ?: '-' }}</p>
                @endif
            @else
                @forelse ((array) $section as $row)
                    <article class="item">
                        <h3>{{ $row['title'] ?? $row['page'] ?? 'Report item' }}</h3>
                        <p class="muted">{{ $row['summary'] ?? $row['rationale'] ?? $row['recommended_action'] ?? '' }}</p>
                        @if (isset($row['score']) || isset($row['visibility_score']) || isset($row['match_score']))
                            <p class="label">Score {{ number_format((float) ($row['score'] ?? $row['visibility_score'] ?? $row['match_score']), 1) }}</p>
                        @endif
                    </article>
                @empty
                    <p class="muted">No items for this section.</p>
                @endforelse
            @endif
        </section>
    @endforeach

    <section>
        <h2>Evidence Links</h2>
        @forelse (($payload['evidence_links'] ?? []) as $link)
            <div class="item">
                <p>{{ $link['label'] }}</p>
                <p class="muted">{{ $link['canonical_url'] ?? '' }}</p>
            </div>
        @empty
            <p class="muted">No evidence links captured.</p>
        @endforelse
    </section>

    <section>
        <h2>Data Provenance</h2>
        <p class="muted">Template {{ data_get($payload, 'provenance.template_version') }} · fingerprint {{ data_get($payload, 'provenance.data_fingerprint') }}</p>
        <div class="grid" style="margin-top: 12px;">
            @foreach ((array) data_get($payload, 'provenance.source_row_ids', []) as $table => $ids)
                <div class="metric">
                    <p class="label">{{ $table }}</p>
                    <p class="value">{{ count((array) $ids) }}</p>
                </div>
            @endforeach
        </div>
    </section>
@endsection
