@extends('layouts.admin', ['title' => 'FAQ Intelligence'])

@section('pageHeader')
    <x-page-header title="FAQ Intelligence" />
@endsection

@section('pageDescription')
    <x-page-description>Detect missing buyer questions, generate FAQ opportunities, and publish answer-ready FAQ content.</x-page-description>
@endsection

@section('filterBar')
    <form method="GET" action="{{ route('admin.faq-intelligence.index') }}">
        <div class="grid gap-3 md:grid-cols-4 xl:grid-cols-7">
            <select name="locale" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                <option value="">All locales</option>
                <option value="en" @selected(($filters['locale'] ?? '') === 'en')>EN</option>
                <option value="nl" @selected(($filters['locale'] ?? '') === 'nl')>NL</option>
            </select>
            <select name="page_type" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                <option value="">All page types</option>
                @foreach ($pageTypes as $type)
                    <option value="{{ $type->value }}" @selected(($filters['page_type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach ($workflowStatuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
                @foreach (\App\Enums\FaqStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>FAQ {{ $status->label() }}</option>
                @endforeach
            </select>
            <input name="market" value="{{ $filters['market'] ?? '' }}" placeholder="Market" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <input name="solution" value="{{ $filters['solution'] ?? '' }}" placeholder="Solution" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <input name="score_min" value="{{ $filters['score_min'] ?? '' }}" placeholder="Min score" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <button class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white">Filter</button>
        </div>
    </form>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Total FAQ's" :value="$totalFaqs" />
        <x-metric-card label="Published" :value="$publishedFaqs" />
        <x-metric-card label="AI Visibility" :value="$averageAiVisibility" />
        <x-metric-card label="SEO impact" :value="$averageSeo" />
        <x-metric-card label="Conversion" :value="$averageConversion" />
    </x-metric-section>
@endsection

@section('content')
    @if (session('status'))
        <div class="mb-4 rounded border border-border bg-surface px-3 py-2 text-sm text-textPrimary">{{ session('status') }}</div>
    @endif

    <div class="mb-8 grid gap-6 xl:grid-cols-3">
        @foreach ([
            'Coverage per page type' => $coverageByPageType,
            'Coverage per market' => $coverageByMarket,
            'Coverage per solution' => $coverageBySolution,
        ] as $title => $rows)
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">{{ $title }}</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($rows as $row)
                        <div class="rounded border border-border bg-background px-3 py-2 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-medium text-textPrimary">{{ $row['label'] }}</span>
                                <span class="text-xs text-textSecondary">{{ $row['pages'] }} pages</span>
                            </div>
                            <p class="mt-1 text-xs text-textSecondary">Coverage {{ $row['coverage'] }} · Opportunity {{ $row['opportunity'] }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No audit data yet.</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-sm font-semibold text-textPrimary">Analyze page</h2>
            <p class="mt-1 text-sm text-textSecondary">Paste the current page signals. The engine checks page and site FAQ coverage, then generates prioritized FAQ candidates.</p>

            <form method="POST" action="{{ route('admin.faq-intelligence.analyze') }}" class="mt-5 grid gap-4">
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-medium text-textSecondary">Page title</span>
                        <input name="page_title" value="{{ old('page_title') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-textSecondary">Page slug</span>
                        <input name="page_slug" value="{{ old('page_slug', 'unknown') }}" required class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-textSecondary">Page type</span>
                        <select name="page_type" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                            @foreach ($pageTypes as $type)
                                <option value="{{ $type->value }}" @selected(old('page_type') === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-textSecondary">Locale</span>
                        <select name="locale" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                            <option value="en">EN</option>
                            <option value="nl">NL</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-textSecondary">Sector</span>
                        <input name="sector" value="{{ old('sector') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-textSecondary">Solution type</span>
                        <input name="solution_type" value="{{ old('solution_type') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                    </label>
                </div>

                <label class="block">
                    <span class="text-xs font-medium text-textSecondary">Meta title</span>
                    <input name="meta_title" value="{{ old('meta_title') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-textSecondary">Meta description</span>
                    <textarea name="meta_description" rows="2" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('meta_description') }}</textarea>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-textSecondary">H1</span>
                    <input name="h1" value="{{ old('h1') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-textSecondary">H2's, one per line</span>
                    <textarea name="h2s" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('h2s') }}</textarea>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-textSecondary">Content</span>
                    <textarea name="content" rows="8" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('content') }}</textarea>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-textSecondary">Internal links, one per line</span>
                    <textarea name="internal_links" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('internal_links') }}</textarea>
                </label>

                <div>
                    <button class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white">Analyze page</button>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Top pages without FAQ coverage</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($topMissingPages as $audit)
                        <div class="rounded border border-border p-3">
                            <p class="text-sm font-semibold text-textPrimary">{{ $audit->page_title ?: $audit->page_slug }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $audit->page_type }} / {{ $audit->locale }}</p>
                            <p class="mt-2 text-xs text-textSecondary">Opportunity: {{ number_format((float) $audit->faq_opportunity_score, 1) }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No audits yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Latest audits</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($latestAudits as $audit)
                        <div class="rounded border border-border p-3 text-sm">
                            <p class="font-medium text-textPrimary">{{ $audit->page_title ?: $audit->page_slug }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ optional($audit->created_at)->format('Y-m-d H:i') }} · Coverage {{ number_format((float) $audit->faq_coverage_score, 1) }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No FAQ audits have been run.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Top duplicate risks</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($topDuplicateRisks as $risk)
                        <div class="rounded border border-border p-3">
                            <p class="text-sm font-semibold text-textPrimary">{{ $risk['question'] }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ str($risk['risk_type'])->replace('_', ' ')->title() }} · {{ $risk['advice'] }} · {{ $risk['count'] }} FAQ's</p>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No duplicate risks detected.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="mt-6 rounded-lg border border-border bg-surface p-5">
        <h2 class="text-sm font-semibold text-textPrimary">Top FAQ opportunities</h2>
        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            @forelse ($topFaqOpportunities as $audit)
                <div class="rounded border border-border bg-background p-3 text-sm">
                    <p class="font-medium text-textPrimary">{{ $audit->page_title ?: $audit->page_slug }}</p>
                    <p class="mt-1 text-xs text-textSecondary">{{ $audit->page_type }} / {{ $audit->locale }} · {{ $audit->status?->label() ?? $audit->status }}</p>
                    <p class="mt-2 text-xs text-textSecondary">Opportunity {{ number_format((float) $audit->faq_opportunity_score, 1) }} · AI {{ number_format((float) $audit->ai_visibility_impact_score, 1) }}</p>
                </div>
            @empty
                <p class="text-sm text-textSecondary">No opportunities yet.</p>
            @endforelse
        </div>
    </div>
@endsection
