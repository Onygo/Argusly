@extends('layouts.app', ['title' => 'Brand Growth Plans', 'pageWidth' => 'wide'])

@section('pageHeader')
    <x-page-header title="Brand Growth Plans" />
@endsection

@section('pageDescription')
    <x-page-description>Evidence-backed strategic plans for audiences, positioning, authority, visibility, and prioritized growth actions.</x-page-description>
@endsection

@section('primaryActions')
    @if ($latestPlan)
        <a href="{{ route('app.agentic-marketing.brand-growth-plans.show', ['plan' => $latestPlan->id, 'workspace_id' => $workspace->id]) }}" class="pl-btn-ghost">
            <i data-lucide="history" class="h-4 w-4"></i>
            <span>Latest plan</span>
        </a>
    @endif
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Plans" :value="$summary['plans'] ?? 0" helper="versioned snapshots" />
        <x-metric-card label="Pending findings" :value="$summary['pending_findings'] ?? 0" helper="awaiting review" />
        <x-metric-card label="Approved findings" :value="$summary['approved_findings'] ?? 0" helper="eligible for promotion" />
        <x-metric-card label="Audience proposals" :value="$summary['pending_audiences'] ?? 0" helper="awaiting review" />
    </x-metric-section>
@endsection

@section('content')
    <div class="space-y-6">
        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="grid gap-5 xl:grid-cols-[1fr_420px]">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ $workspace->display_name ?? $workspace->name }}</span>
                        @if ($latestPlan)
                            <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">v{{ $latestPlan->version }}</span>
                            <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $latestPlan->status?->value ?? $latestPlan->status) }}</span>
                        @endif
                    </div>
                    <h2 class="mt-3 text-xl font-semibold text-textPrimary">{{ $latestPlan ? $latestPlan->business_objective : 'Generate the first strategic snapshot' }}</h2>
                    <p class="mt-2 max-w-3xl text-sm text-textSecondary">
                        {{ $latestPlan?->brand_objective ?? 'The first plan will combine available company, brand, content, competitor, signal, visibility, and connector context into reviewed strategic recommendations.' }}
                    </p>
                    @if ($latestPlan)
                        <div class="mt-4 grid gap-3 sm:grid-cols-4">
                            <div class="rounded-md border border-border bg-background px-3 py-2 text-xs text-textSecondary">Confidence <span class="block text-base font-semibold text-textPrimary">{{ number_format((float) $latestPlan->confidence_score, 1) }}</span></div>
                            <div class="rounded-md border border-border bg-background px-3 py-2 text-xs text-textSecondary">Findings <span class="block text-base font-semibold text-textPrimary">{{ $latestPlan->findings_count }}</span></div>
                            <div class="rounded-md border border-border bg-background px-3 py-2 text-xs text-textSecondary">Audiences <span class="block text-base font-semibold text-textPrimary">{{ $latestPlan->audience_proposals_count }}</span></div>
                            <div class="rounded-md border border-border bg-background px-3 py-2 text-xs text-textSecondary">Generated <span class="block text-base font-semibold text-textPrimary">{{ $latestPlan->generated_at?->diffForHumans() ?? 'Draft' }}</span></div>
                        </div>
                    @endif
                </div>

                <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.generate', ['workspace_id' => $workspace->id]) }}" class="rounded-lg border border-border bg-background p-4">
                    @csrf
                    <h3 class="text-sm font-semibold text-textPrimary">Generate Draft</h3>
                    <div class="mt-4 grid gap-3">
                        <label class="text-xs font-medium text-textSecondary">Planning horizon
                            <select name="planning_horizon" class="pl-input mt-1 w-full">
                                <option value="next_90_days">Next 90 days</option>
                                <option value="next_6_months">Next 6 months</option>
                                <option value="next_12_months">Next 12 months</option>
                            </select>
                        </label>
                        <label class="text-xs font-medium text-textSecondary">Site context
                            <select name="client_site_id" class="pl-input mt-1 w-full">
                                <option value="">Workspace-wide</option>
                                @foreach ($clientSites as $site)
                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-xs font-medium text-textSecondary">Business objective
                            <textarea name="business_objective" rows="3" class="pl-input mt-1 w-full" placeholder="Grow qualified demand in priority markets">{{ old('business_objective') }}</textarea>
                        </label>
                        <label class="text-xs font-medium text-textSecondary">Brand objective
                            <textarea name="brand_objective" rows="3" class="pl-input mt-1 w-full" placeholder="Become more visible, credible, relevant, and memorable">{{ old('brand_objective') }}</textarea>
                        </label>
                        <button class="pl-btn-primary justify-center" type="submit">
                            <i data-lucide="sparkles" class="h-4 w-4"></i>
                            <span>Generate draft plan</span>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-sm font-semibold text-textPrimary">Plan History</h2>
            </div>
            <div class="divide-y divide-border">
                @forelse ($plans as $plan)
                    <a href="{{ route('app.agentic-marketing.brand-growth-plans.show', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}" class="block px-5 py-4 transition hover:bg-surfaceMuted">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">v{{ $plan->version }}</span>
                                    <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $plan->status?->value ?? $plan->status) }}</span>
                                    <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ number_format((float) $plan->confidence_score, 1) }} confidence</span>
                                </div>
                                <h3 class="mt-2 text-sm font-semibold text-textPrimary">{{ $plan->business_objective ?: 'Brand Growth Plan' }}</h3>
                                <p class="mt-1 text-xs text-textSecondary">{{ $plan->generated_at?->toFormattedDateString() ?? $plan->created_at?->toFormattedDateString() }}</p>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-xs text-textSecondary sm:grid-cols-3">
                                <span class="rounded-md border border-border bg-background px-3 py-2">{{ $plan->findings_count }} findings</span>
                                <span class="rounded-md border border-border bg-background px-3 py-2">{{ $plan->audience_proposals_count }} audiences</span>
                                <span class="rounded-md border border-border bg-background px-3 py-2">{{ str_replace('_', ' ', $plan->planning_horizon) }}</span>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="px-5 py-6 text-sm text-textSecondary">No Brand Growth Plans yet.</div>
                @endforelse
            </div>
            <div class="border-t border-border px-5 py-4">{{ $plans->links() }}</div>
        </section>
    </div>
@endsection
