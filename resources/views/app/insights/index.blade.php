@extends('layouts.app', ['title' => 'Insights'])

@section('pageHeader')
    <x-page-header title="Insights">
        <x-slot:description>Open visibility, analytics, audit, and competitor workflows for a connected site.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        @if ($sites->isEmpty())
            <x-settings.empty-state
                title="No sites connected yet"
                description="Connect a site first to unlock the Insights section."
            />

            <div>
                <a href="{{ route('app.sites') }}" class="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">
                    Open Sites
                </a>
            </div>
        @else
            <section class="rounded-lg border border-border bg-surface p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-textPrimary">Human Content</h2>
                        <p class="mt-1 text-sm text-textSecondary">Review editorial quality, originality, AI fingerprint risk, blocked articles, and repeated structures across generated content.</p>
                    </div>
                    <a href="{{ route('app.insights.human-content.index') }}" class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse hover:bg-primary/90">
                        <i data-lucide="activity" class="h-4 w-4"></i>
                        Open dashboard
                    </a>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                @foreach ($sites as $site)
                    @php
                        $analyticsSite = $site->analyticsSite;
                    @endphp
                    <article class="rounded-lg border border-border bg-surface p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-textPrimary">{{ $site->name }}</h2>
                                <p class="mt-1 text-sm text-textSecondary">{{ $site->base_url ?: $site->site_url }}</p>
                            </div>
                            <span class="inline-flex rounded px-2 py-1 text-xs {{ $site->status === 'connected' ? 'bg-emerald-100 text-emerald-800' : ($site->status === 'disabled' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700') }}">
                                {{ ucfirst((string) $site->status) }}
                            </span>
                        </div>

                        <div class="mt-6 grid gap-6 sm:grid-cols-3">
                            <div class="rounded-lg border border-border bg-background p-4">
                                <p class="text-xs text-textSecondary">LLM queries</p>
                                <p class="mt-1 text-sm font-semibold text-textPrimary">{{ (int) $site->llm_tracking_queries_count }}</p>
                            </div>
                            <div class="rounded-lg border border-border bg-background p-4">
                                <p class="text-xs text-textSecondary">Competitors</p>
                                <p class="mt-1 text-sm font-semibold text-textPrimary">{{ (int) $site->competitors_count }}</p>
                            </div>
                            <div class="rounded-lg border border-border bg-background p-4">
                                <p class="text-xs text-textSecondary">Audit runs</p>
                                <p class="mt-1 text-sm font-semibold text-textPrimary">{{ (int) $site->seo_audits_count }}</p>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-textSecondary">
                            <span class="inline-flex items-center rounded-md border border-border bg-background px-2.5 py-1">Workspace: {{ $site->workspace?->name ?? 'n/a' }}</span>
                            <span class="inline-flex items-center rounded-md border border-border bg-background px-2.5 py-1">
                                Analytics:
                                @if (! $analyticsSite)
                                    Not enabled
                                @elseif (! $analyticsSite->is_enabled)
                                    Disabled
                                @elseif (! $analyticsSite->verified_at)
                                    Pending verification
                                @else
                                    Verified
                                @endif
                            </span>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <a href="{{ route('app.sites.insights.index', $site) }}" class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse hover:bg-primary/90">
                                Open insights
                            </a>
                            <a href="{{ route('app.sites.show', $site) }}" class="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">
                                Site setup
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
@endsection
