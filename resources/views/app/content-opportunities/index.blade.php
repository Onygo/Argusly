@extends('layouts.app', ['title' => 'Content Opportunity Engine'])

@section('pageHeader')
    <x-page-header title="Content Opportunity Engine">
        <x-slot:description>Generate net-new content ideas from company, competitor, SEO/AEO, lifecycle, AI visibility, and buyer journey signals.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-textPrimary">Content Opportunity Engine</h2>
                <p class="mt-1 text-textSecondary">Generate net-new content ideas from company, competitor, SEO/AEO, lifecycle, AI visibility, and buyer journey signals.</p>
            </div>
            <form method="POST" action="{{ route('app.agentic-marketing.content-opportunities.run') }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
                @if ($siteId)
                    <input type="hidden" name="client_site_id" value="{{ $siteId }}">
                @endif
                <label class="inline-flex items-center gap-2 text-xs text-textSecondary">
                    <input type="checkbox" name="run_inline" value="1">
                    Run now
                </label>
                <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Generate opportunities</button>
            </form>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Total</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $summary['total'] }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Open</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $summary['open'] }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Strategic impact</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $summary['strategic'] }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Avg priority</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $summary['avg_priority'], 1) }}</p>
            </div>
        </div>

        @if ($executionSettings)
            <div class="rounded-lg border {{ $executionSettings->isAutonomous() ? 'border-amber-200 bg-amber-50' : 'border-border bg-surface' }} p-4">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $executionSettings->isAutonomous() ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-800' }}">
                                {{ $executionSettings->isAutonomous() ? 'Autonomous mode' : 'Guided mode' }}
                            </span>
                            <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ $workspace->display_name ?? $workspace->name }}</span>
                        </div>
                        <p class="mt-2 text-sm text-textPrimary">
                            {{ $executionSettings->isAutonomous() ? 'Selected opportunity actions may run automatically only when the action type, site, credit limit, and approval thresholds allow it.' : 'Opportunities create briefs and chained plans for customer review before execution.' }}
                        </p>
                    </div>
                    <a href="{{ route('app.agentic-marketing.index', ['execution_workspace_id' => $workspace->id]) }}" class="pl-btn-ghost shrink-0">
                        <i data-lucide="settings" class="h-4 w-4"></i>
                        <span>Execution settings</span>
                    </a>
                </div>
            </div>
        @endif

        <div class="rounded-lg border border-border bg-surface p-4">
            <form method="GET" action="{{ route('app.agentic-marketing.content-opportunities.index') }}" class="grid gap-3 md:grid-cols-6">
                <select name="workspace_id" class="pl-select bg-background" onchange="this.form.submit()">
                    @foreach ($workspaces as $item)
                        <option value="{{ $item->id }}" @selected((string) $item->id === (string) $workspace->id)>{{ $item->display_name }}</option>
                    @endforeach
                </select>
                <select name="client_site_id" class="pl-select bg-background">
                    <option value="">All sites</option>
                    @foreach ($workspace->clientSites as $site)
                        <option value="{{ $site->id }}" @selected((string) $site->id === (string) $siteId)>{{ $site->name }}</option>
                    @endforeach
                </select>
                <select name="type" class="pl-select bg-background">
                    <option value="">All types</option>
                    @foreach (['article_idea','comparison_page','implementation_guide','faq_opportunity','answer_block_opportunity','bofu_page','use_case_page','industry_page','feature_page','refresh_opportunity','campaign_cluster'] as $type)
                        <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ str_replace('_', ' ', $type) }}</option>
                    @endforeach
                </select>
                <select name="funnel_stage" class="pl-select bg-background">
                    <option value="">All stages</option>
                    @foreach (['awareness','consideration','decision','retention'] as $stage)
                        <option value="{{ $stage }}" @selected(($filters['funnel_stage'] ?? '') === $stage)>{{ $stage }}</option>
                    @endforeach
                </select>
                <select name="sort" class="pl-select bg-background">
                    <option value="priority" @selected(($filters['sort'] ?? '') === 'priority')>Priority</option>
                    <option value="business_value" @selected(($filters['sort'] ?? '') === 'business_value')>Business value</option>
                    <option value="urgency" @selected(($filters['sort'] ?? '') === 'urgency')>Urgency</option>
                    <option value="newest" @selected(($filters['sort'] ?? '') === 'newest')>Newest</option>
                </select>
                <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Filter</button>
            </form>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-4 xl:col-span-2">
                @forelse ($opportunities as $opportunity)
                    @php
                        $policy = ($opportunityPolicies ?? collect())->get((string) $opportunity->id, []);
                        $briefDecision = (array) ($policy['brief'] ?? []);
                        $chainedDecision = (array) ($policy['chained'] ?? []);
                        $primaryDecision = $briefDecision;
                        $decisionMode = (string) data_get($primaryDecision, 'policy_snapshot.mode', 'guided');
                        $isBlocked = (bool) data_get($primaryDecision, 'blocked', false);
                        $needsApproval = (bool) data_get($primaryDecision, 'requires_approval', false);
                        $canAutoRun = (bool) data_get($primaryDecision, 'allowed', false) && $decisionMode === 'autonomous';
                    @endphp
                    <article class="rounded-lg border border-border bg-surface p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-xs text-textSecondary">{{ str_replace('_', ' ', $opportunity->type) }} · {{ $opportunity->funnel_stage }} · {{ $opportunity->primary_search_intent }}</p>
                                    @if ($isBlocked)
                                        <span class="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-medium text-rose-800">Blocked</span>
                                    @elseif ($needsApproval)
                                        <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800">This action requires approval</span>
                                    @elseif ($canAutoRun)
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800">This action can run autonomously</span>
                                    @else
                                        <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-800">Guided review</span>
                                    @endif
                                </div>
                                <h2 class="mt-1 text-lg font-semibold text-textPrimary">{{ $opportunity->title }}</h2>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-textSecondary">Priority</p>
                                <p class="text-xl font-semibold text-textPrimary">{{ number_format((float) $opportunity->priority_score, 1) }}</p>
                            </div>
                        </div>

                        <p class="mt-3 text-sm text-textSecondary">{{ $opportunity->reasoning }}</p>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-xs font-medium text-textPrimary">Why this matters</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $opportunity->why_this_matters }}</p>
                            </div>
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-xs font-medium text-textPrimary">Why now</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $opportunity->why_now }}</p>
                            </div>
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-xs font-medium text-textPrimary">Competitor pressure</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $opportunity->competitor_pressure }}</p>
                            </div>
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-xs font-medium text-textPrimary">AI visibility opportunity</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $opportunity->ai_visibility_opportunity }}</p>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 text-xs text-textSecondary md:grid-cols-4">
                            <div>Audience: <span class="text-textPrimary">{{ str_replace('_', ' ', (string) $opportunity->target_audience) }}</span></div>
                            <div>Impact: <span class="text-textPrimary">{{ $opportunity->expected_impact }}</span></div>
                            <div>Urgency: <span class="text-textPrimary">{{ number_format((float) $opportunity->urgency_score, 1) }}</span></div>
                            <div>Business: <span class="text-textPrimary">{{ number_format((float) $opportunity->business_value_score, 1) }}</span></div>
                        </div>
                        <div class="mt-3 text-xs text-textSecondary">
                            CTA: <span class="text-textPrimary">{{ $opportunity->suggested_cta }}</span>
                            · Schema: <span class="text-textPrimary">{{ $opportunity->suggested_schema }}</span>
                        </div>
                        @php
                            $opportunitySiteId = (string) ($opportunity->client_site_id ?? '');
                            $briefSiteOptions = $opportunitySiteId !== ''
                                ? $workspace->clientSites->where('id', $opportunitySiteId)->values()
                                : $workspace->clientSites->values();
                            $singleBriefSiteId = $briefSiteOptions->count() === 1 ? (string) $briefSiteOptions->first()->id : '';
                            $destinationLabel = $opportunity->site?->name
                                ?: ($singleBriefSiteId !== '' ? $briefSiteOptions->first()?->name : 'Select publishing site');
                        @endphp
                        <div class="mt-4 grid gap-2 text-xs text-textSecondary md:grid-cols-3">
                            <div class="rounded-md border border-border bg-background px-3 py-2">Estimated credit impact <span class="font-semibold text-textPrimary">{{ number_format((int) ($policy['estimated_credits'] ?? 0)) }}</span></div>
                            <div class="rounded-md border border-border bg-background px-3 py-2">Publishing destination <span class="font-semibold text-textPrimary">{{ $destinationLabel }}</span></div>
                            <div class="rounded-md border border-border bg-background px-3 py-2">Execution mode <span class="font-semibold text-textPrimary">{{ ucfirst($decisionMode) }}</span></div>
                        </div>
                        <p class="mt-2 rounded-md border border-border bg-background px-3 py-2 text-xs text-textSecondary">
                            {{ data_get($primaryDecision, 'reason', 'Execution policy will be checked again before work is created.') }}
                        </p>
                        @if ($isBlocked)
                            <p class="mt-2 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">Blocked reason: {{ data_get($primaryDecision, 'reason') }}</p>
                        @endif
                        <form method="POST" action="{{ route('app.agentic-marketing.content-opportunities.brief.create', $opportunity) }}" class="mt-4 flex flex-wrap items-end gap-2">
                            @csrf
                            @if ($briefSiteOptions->count() > 1)
                                <div class="min-w-56">
                                    <label class="mb-1 block text-xs text-textSecondary">Publishing site</label>
                                    <select name="site_id" class="pl-select bg-background" required>
                                        <option value="">Select site</option>
                                        @foreach ($briefSiteOptions as $site)
                                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @elseif ($singleBriefSiteId !== '')
                                <input type="hidden" name="site_id" value="{{ $singleBriefSiteId }}">
                            @else
                                <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Connect a site before creating a brief.
                                </div>
                            @endif

                            @if ($briefSiteOptions->isNotEmpty())
                                <button name="mode" value="single" class="rounded-md border border-border bg-background px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                    {{ $executionSettings->isGuided() ? 'Brief maken' : ($canAutoRun ? 'Allow agent to execute' : 'Run once manually') }}
                                </button>
                                <button name="mode" value="chained" class="rounded-md border border-border bg-background px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">Chained plan maken</button>
                                @if ($executionSettings->isAutonomous())
                                    <button type="button" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900">Require approval for this action</button>
                                @else
                                    <button type="button" class="rounded-md border border-border bg-background px-3 py-2 text-xs font-medium text-textPrimary">Submit for approval</button>
                                @endif
                            @endif
                        </form>
                        @error('site_id')
                            <p class="mt-2 text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                    </article>
                @empty
                    <div class="rounded-lg border border-border bg-surface p-6 text-sm text-textSecondary">No opportunities yet. Run the engine to generate the first set.</div>
                @endforelse

                <div>{{ $opportunities->links() }}</div>
            </div>

            <aside class="space-y-4">
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Recent runs</h2>
                    <div class="mt-3 space-y-2 text-xs text-textSecondary">
                        @forelse ($runs as $run)
                            <div class="rounded-md border border-border bg-background p-3">
                                <div class="flex justify-between gap-2">
                                    <span>{{ strtoupper($run->status) }}</span>
                                    <span>{{ optional($run->created_at)->format('Y-m-d H:i') }}</span>
                                </div>
                                <p class="mt-1">{{ $run->created_count }} created, {{ $run->refreshed_count }} refreshed</p>
                            </div>
                        @empty
                            <p>No runs yet.</p>
                        @endforelse
                    </div>
                </div>
            </aside>
        </div>
    </div>
@endsection
