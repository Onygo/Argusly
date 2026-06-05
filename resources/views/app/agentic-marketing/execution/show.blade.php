@extends('layouts.app', ['title' => 'Opportunity execution', 'pageWidth' => 'wide'])

@section('content')
    <div class="space-y-6">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('app.agentic-marketing.objectives.show', $opportunity->objective) }}" class="text-sm text-textSecondary hover:text-textPrimary">{{ $opportunity->objective?->name ?? 'Agentic Marketing' }}</a>
                <h1 class="mt-2 text-xl font-semibold text-textPrimary">{{ $opportunity->title }}</h1>
                <p class="mt-1 max-w-4xl text-sm text-textSecondary">Prepare briefs, drafts, answer blocks, internal links, schema, metadata, CTA blocks, social post handoff copy, reviewer flow, and automation schedules. Social posts are prepared for external publishing tools; PublishLayer does not publish them.</p>
            </div>
            <form method="POST" action="{{ route('app.agentic-marketing.opportunities.execution.prepare', $opportunity) }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <select name="mode" class="pl-input text-sm">
                    <option value="manual">Manual</option>
                    <option value="semi_autonomous">Assisted prep</option>
                    <option value="autonomous">Future auto-prep</option>
                </select>
                <label class="inline-flex items-center gap-2 text-xs text-textSecondary">
                    <input type="checkbox" name="run_inline" value="1">
                    Prepare immediately
                </label>
                <button class="pl-btn-primary"><i data-lucide="workflow" class="h-4 w-4"></i><span>Prepare review assets</span></button>
            </form>
        </header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if (! $pipeline)
            <div class="rounded-lg border border-border bg-surface p-6 text-sm text-textSecondary">No execution pipeline has been prepared for this opportunity yet.</div>
        @else
            @php
                $result = (array) ($pipeline->result ?? []);
                $why = (array) data_get($result, 'why_this_matters', []);
                $scores = (array) data_get($result, 'confidence_risk_scores', []);
                $readiness = (array) data_get($result, 'publishing_readiness', []);
                $timeline = collect((array) data_get($result, 'execution_timeline', []));
                $assetInventory = collect((array) data_get($result, 'asset_inventory', []));
                $scoreCards = [
                    'confidence_score' => 'Confidence',
                    'ai_visibility_impact' => 'AI visibility impact',
                    'seo_impact' => 'SEO impact',
                    'brand_alignment' => 'Brand alignment',
                    'hallucination_risk' => 'Hallucination risk',
                    'publishing_risk' => 'Handoff risk',
                ];
                $timelineClasses = [
                    'completed' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                    'ready' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                    'pending' => 'border-amber-200 bg-amber-50 text-amber-900',
                    'pending_approval' => 'border-amber-200 bg-amber-50 text-amber-900',
                    'blocked' => 'border-rose-200 bg-rose-50 text-rose-900',
                    'changes_requested' => 'border-rose-200 bg-rose-50 text-rose-900',
                ];
            @endphp

            <section class="grid gap-3 md:grid-cols-4">
                <div class="rounded-lg border border-border bg-surface p-4">
                    <p class="text-xs text-textSecondary">Status</p>
                    <p class="mt-1 text-lg font-semibold text-textPrimary">{{ str_replace('_', ' ', $pipeline->status) }}</p>
                </div>
                <div class="rounded-lg border border-border bg-surface p-4">
                    <p class="text-xs text-textSecondary">Approvals</p>
                    <p class="mt-1 text-lg font-semibold text-textPrimary">{{ str_replace('_', ' ', $pipeline->approval_status) }}</p>
                </div>
                <div class="rounded-lg border border-border bg-surface p-4">
                    <p class="text-xs text-textSecondary">Publishing readiness</p>
                    <p class="mt-1 text-lg font-semibold text-textPrimary">{{ str_replace('_', ' ', $pipeline->publishing_readiness) }}</p>
                </div>
                <div class="rounded-lg border border-border bg-surface p-4">
                    <p class="text-xs text-textSecondary">Generated assets</p>
                    <p class="mt-1 text-lg font-semibold text-textPrimary">{{ $pipeline->assets_count }}</p>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                <div class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Why this matters</p>
                            <h2 class="mt-2 text-lg font-semibold text-textPrimary">{{ $why['summary'] ?? 'This opportunity can improve AI visibility and content execution quality.' }}</h2>
                            <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $why['business_goal'] ?? 'Improve content performance and publishing readiness.' }}</p>
                        </div>
                        <div class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                            <p class="text-xs text-textSecondary">Human validation</p>
                            <p class="mt-1 font-semibold text-textPrimary">{{ data_get($scores, 'requires_human_validation') ? 'Required' : 'Pre-cleared' }}</p>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-3 md:grid-cols-3">
                        <div class="rounded-md border border-border bg-background p-3">
                            <p class="text-xs text-textSecondary">ICP</p>
                            <p class="mt-1 text-sm font-medium text-textPrimary">{{ $why['icp'] ?? 'Not specified' }}</p>
                        </div>
                        <div class="rounded-md border border-border bg-background p-3">
                            <p class="text-xs text-textSecondary">Search intent</p>
                            <p class="mt-1 text-sm font-medium text-textPrimary">{{ str_replace('_', ' ', (string) ($why['search_intent'] ?? 'Not specified')) }}</p>
                        </div>
                        <div class="rounded-md border border-border bg-background p-3">
                            <p class="text-xs text-textSecondary">AI visibility gap</p>
                            <p class="mt-1 text-sm font-medium text-textPrimary">{{ str_replace('_', ' ', (string) ($why['ai_visibility_gap'] ?? 'Not specified')) }}</p>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Triggered because</p>
                            <ul class="mt-2 space-y-2 text-sm text-textSecondary">
                                @foreach ((array) ($why['triggered_by'] ?? []) as $trigger)
                                    <li class="rounded-md border border-border bg-background px-3 py-2">{{ $trigger }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Why now</p>
                            <p class="mt-2 rounded-md border border-border bg-background px-3 py-3 text-sm leading-6 text-textSecondary">{{ $why['why_now'] ?? 'This pipeline can bundle multiple optimizations into one reviewable execution.' }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Confidence and risk</p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                        @foreach ($scoreCards as $key => $label)
                            @php
                                $value = (int) ($scores[$key] ?? 0);
                                $isRisk = str_contains($key, 'risk');
                            @endphp
                            <div>
                                <div class="flex items-center justify-between gap-3 text-sm">
                                    <span class="text-textSecondary">{{ $label }}</span>
                                    <span class="font-semibold text-textPrimary">{{ $value }}/100</span>
                                </div>
                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-background">
                                    <div class="{{ $isRisk ? 'bg-amber-500' : 'bg-primary' }} h-full rounded-full" style="width: {{ max(0, min(100, $value)) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                <div class="rounded-lg border border-border bg-surface p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Execution timeline</p>
                    <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-5">
                        @foreach ($timeline as $step)
                            @php
                                $status = (string) ($step['status'] ?? 'pending');
                                $class = $timelineClasses[$status] ?? 'border-border bg-background text-textSecondary';
                            @endphp
                            <div class="rounded-md border px-3 py-3 {{ $class }}">
                                <p class="text-sm font-semibold">{{ $step['label'] ?? $step['event'] ?? 'Execution step' }}</p>
                                <p class="mt-1 text-xs">{{ str_replace('_', ' ', $status) }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Publishing readiness</p>
                    <p class="mt-2 text-lg font-semibold text-textPrimary">{{ str_replace('_', ' ', (string) ($readiness['status'] ?? $pipeline->publishing_readiness)) }}</p>
                    @if (! empty($readiness['why_not_ready']))
                        <ul class="mt-3 space-y-2 text-sm text-textSecondary">
                            @foreach ((array) $readiness['why_not_ready'] as $issue)
                                <li class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-950">{{ $issue }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-textSecondary">Next actions</p>
                    <ul class="mt-2 space-y-2 text-sm text-textSecondary">
                        @foreach ((array) ($readiness['next_actions'] ?? []) as $action)
                            <li class="rounded-md border border-border bg-background px-3 py-2">{{ $action }}</li>
                        @endforeach
                    </ul>
                </div>
            </section>

            <section class="rounded-lg border border-border bg-surface p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Generated asset inventory</p>
                        <h2 class="mt-1 text-base font-semibold text-textPrimary">{{ $pipeline->assets_count }} execution assets generated</h2>
                    </div>
                    <p class="text-sm text-textSecondary">Brief, content, SEO, schema, CTA, social handoff, monitoring, and lifecycle work in one approval flow.</p>
                </div>
                <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    @foreach ($assetInventory as $item)
                        <div class="rounded-md border border-border bg-background p-3">
                            <p class="text-sm font-semibold text-textPrimary">{{ $item['label'] ?? str_replace('_', ' ', (string) ($item['type'] ?? 'Asset')) }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) ($item['status'] ?? 'generated')) }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                <div class="space-y-4">
                    @foreach ($pipeline->assets as $asset)
                        <article class="rounded-lg border border-border bg-surface p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs text-textSecondary">{{ str_replace('_', ' ', $asset->type) }}</p>
                                    <h2 class="mt-1 text-base font-semibold text-textPrimary">{{ $asset->title }}</h2>
                                </div>
                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $asset->status) }}</span>
                            </div>

                            @if ($asset->type === 'autonomous_campaign_plan')
                                @php
                                    $articles = (array) data_get($asset->payload, 'articles', []);
                                    $linkedinPosts = (array) data_get($asset->payload, 'linkedin_posts', []);
                                    $refreshSchedule = (array) data_get($asset->payload, 'refresh_schedule', []);
                                    $interlinkMap = (array) data_get($asset->payload, 'interlink_map', []);
                                @endphp

                                <div class="mt-4 space-y-4">
                                    <div class="grid gap-3 md:grid-cols-4">
                                        <div class="rounded-md border border-border bg-background p-3 md:col-span-2">
                                            <p class="text-xs text-textSecondary">Campaign</p>
                                            <p class="mt-1 text-lg font-semibold text-textPrimary">{{ data_get($asset->payload, 'campaign') }}</p>
                                            <p class="mt-1 text-xs text-textSecondary">{{ data_get($asset->payload, 'operating_model') }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs text-textSecondary">Articles</p>
                                            <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ count($articles) }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs text-textSecondary">LinkedIn handoff posts</p>
                                            <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ count($linkedinPosts) }}</p>
                                        </div>
                                    </div>

                                    <div class="rounded-md border border-border bg-background p-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">30 day article plan</p>
                                        <div class="mt-3 grid gap-2 md:grid-cols-2">
                                            @foreach ($articles as $article)
                                                <div class="rounded border border-border bg-surface p-3">
                                                    <div class="flex items-start justify-between gap-2">
                                                        <p class="text-sm font-semibold text-textPrimary">{{ $article['title'] ?? '' }}</p>
                                                        <span class="rounded-full border border-border px-2 py-0.5 text-xs text-textSecondary">Day {{ $article['publish_day'] ?? '?' }}</span>
                                                    </div>
                                                    <p class="mt-1 text-xs text-textSecondary">{{ $article['purpose'] ?? '' }}</p>
                                                    <p class="mt-2 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) ($article['type'] ?? 'article')) }} · {{ $article['primary_cta'] ?? '' }}</p>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="grid gap-4 lg:grid-cols-2">
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">LinkedIn handoff copy</p>
                                            <p class="mt-1 text-xs text-textSecondary">Prepared from the campaign content for copy/export into an external social publishing tool.</p>
                                            <div class="mt-3 max-h-72 overflow-auto space-y-2">
                                                @foreach ($linkedinPosts as $post)
                                                    <div class="rounded border border-border bg-surface p-2 text-sm">
                                                        <p class="font-medium text-textPrimary">Day {{ $post['day'] ?? '?' }} · {{ $post['angle'] ?? '' }}</p>
                                                        <p class="mt-1 text-xs text-textSecondary">{{ $post['hook'] ?? '' }}</p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Interlink map</p>
                                            <p class="mt-2 text-sm text-textPrimary">{{ str_replace('_', ' ', (string) ($interlinkMap['model'] ?? '')) }}</p>
                                            <div class="mt-3 max-h-72 overflow-auto space-y-2">
                                                @foreach ((array) ($interlinkMap['links'] ?? []) as $link)
                                                    <div class="rounded border border-border bg-surface p-2 text-xs text-textSecondary">{{ $link['from'] ?? '' }} -> {{ $link['to'] ?? '' }} · {{ $link['anchor_text'] ?? '' }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid gap-4 lg:grid-cols-3">
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">CTA strategy</p>
                                            <pre class="mt-2 whitespace-pre-wrap text-xs text-textSecondary">{{ json_encode(data_get($asset->payload, 'cta_strategy'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">GEO optimization</p>
                                            <pre class="mt-2 whitespace-pre-wrap text-xs text-textSecondary">{{ json_encode(data_get($asset->payload, 'geo_optimization'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">AI monitoring</p>
                                            <pre class="mt-2 whitespace-pre-wrap text-xs text-textSecondary">{{ json_encode(data_get($asset->payload, 'ai_visibility_monitoring'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                    </div>
                                </div>
                            @elseif ($asset->type === 'ai_visibility_scorecard')
                                @php
                                    $scores = (array) data_get($asset->payload, 'scores', []);
                                    $summary = (array) data_get($asset->payload, 'summary', []);
                                    $scoreLabels = [
                                        'ai_discoverability_score' => 'AI discoverability',
                                        'answer_readiness_score' => 'Answer readiness',
                                        'entity_richness_score' => 'Entity richness',
                                        'citation_likelihood_score' => 'Citation likelihood',
                                        'semantic_completeness_score' => 'Semantic completeness',
                                        'freshness_decay_score' => 'Freshness decay',
                                        'competitor_overlap_score' => 'Competitor overlap',
                                    ];
                                @endphp

                                <div class="mt-4 space-y-4">
                                    <div class="grid gap-3 md:grid-cols-2">
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs text-textSecondary">Average AI visibility</p>
                                            <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $summary['overall_average'] ?? 'n/a' }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs text-textSecondary">Highest risk</p>
                                            <p class="mt-1 text-lg font-semibold text-textPrimary">{{ str_replace('_', ' ', (string) ($summary['highest_risk'] ?? 'n/a')) }}</p>
                                        </div>
                                    </div>

                                    @forelse ($scores as $scorecard)
                                        <div class="rounded-md border border-border bg-background p-4">
                                            <div class="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <p class="text-sm font-semibold text-textPrimary">{{ $scorecard['title'] ?? 'Article' }}</p>
                                                    <p class="mt-1 text-xs text-textSecondary">{{ $scorecard['published_url'] ?? '' }}</p>
                                                </div>
                                                <div class="text-right">
                                                    <p class="text-xs text-textSecondary">Overall</p>
                                                    <p class="text-2xl font-semibold text-textPrimary">{{ $scorecard['overall_ai_visibility_score'] ?? 0 }}</p>
                                                </div>
                                            </div>
                                            <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                                @foreach ($scoreLabels as $key => $label)
                                                    <div class="rounded border border-border bg-surface p-2">
                                                        <p class="text-xs text-textSecondary">{{ $label }}</p>
                                                        <p class="mt-1 text-lg font-semibold text-textPrimary">{{ $scorecard[$key] ?? 0 }}</p>
                                                    </div>
                                                @endforeach
                                            </div>
                                            @if (! empty($scorecard['recommendations']))
                                                <div class="mt-4 rounded border border-amber-200 bg-amber-50 p-3">
                                                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">Recommendations</p>
                                                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-950">
                                                        @foreach ((array) $scorecard['recommendations'] as $recommendation)
                                                            <li>{{ $recommendation }}</li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                        </div>
                                    @empty
                                        <div class="rounded-md border border-border bg-background p-3 text-sm text-textSecondary">No article available for scoring yet.</div>
                                    @endforelse
                                </div>
                            @elseif ($asset->type === 'strategic_cluster_proposal')
                                @php
                                    $missing = (array) data_get($asset->payload, 'missing', []);
                                    $sequence = (array) data_get($asset->payload, 'recommended_sequence', []);
                                @endphp

                                <div class="mt-4 space-y-4">
                                    <div class="grid gap-3 md:grid-cols-3">
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs text-textSecondary">Topic</p>
                                            <p class="mt-1 text-lg font-semibold text-textPrimary">{{ data_get($asset->payload, 'topic') }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs text-textSecondary">Estimated impact</p>
                                            <p class="mt-1 text-lg font-semibold text-textPrimary">{{ data_get($asset->payload, 'estimated_impact') }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs text-textSecondary">Priority</p>
                                            <p class="mt-1 text-lg font-semibold text-textPrimary">{{ data_get($asset->payload, 'priority') }}</p>
                                        </div>
                                    </div>

                                    <div class="rounded-md border border-border bg-background p-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Missing authority assets</p>
                                        <div class="mt-3 grid gap-2 md:grid-cols-2">
                                            @foreach ($missing as $gap)
                                                <div class="rounded border border-border bg-surface p-3">
                                                    <p class="text-sm font-semibold text-textPrimary">{{ $gap['recommended_title'] ?? str_replace('_', ' ', (string) ($gap['type'] ?? 'Missing asset')) }}</p>
                                                    <p class="mt-1 text-xs text-textSecondary">{{ $gap['reason'] ?? '' }}</p>
                                                    <p class="mt-2 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) ($gap['type'] ?? '')) }} · {{ $gap['funnel_stage'] ?? '' }}</p>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="rounded-md border border-border bg-background p-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Recommended sequence</p>
                                        <div class="mt-3 space-y-2">
                                            @foreach ($sequence as $step)
                                                <div class="flex gap-3 rounded border border-border bg-surface p-3 text-sm">
                                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">{{ $step['order'] ?? $loop->iteration }}</span>
                                                    <div>
                                                        <p class="font-medium text-textPrimary">{{ $step['title'] ?? '' }}</p>
                                                        <p class="mt-1 text-xs text-textSecondary">{{ $step['reason'] ?? '' }}</p>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @elseif ($asset->type === 'execution_graph')
                                @php
                                    $nodes = (array) data_get($asset->payload, 'nodes', []);
                                    $edges = (array) data_get($asset->payload, 'edges', []);
                                    $criticalPath = (array) data_get($asset->payload, 'critical_path', []);
                                @endphp

                                <div class="mt-4 space-y-4">
                                    <div class="rounded-md border border-border bg-background p-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Execution graph</p>
                                        <div class="mt-3 space-y-3">
                                            @foreach ($nodes as $node)
                                                @php
                                                    $nodeId = (string) ($node['id'] ?? '');
                                                    $isCritical = in_array($nodeId, $criticalPath, true);
                                                @endphp
                                                <div class="@class([
                                                    'rounded-md border p-3',
                                                    'border-primary/30 bg-primary/5' => $isCritical,
                                                    'border-border bg-surface' => ! $isCritical,
                                                ])">
                                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                                        <div>
                                                            <p class="text-sm font-semibold text-textPrimary">{{ $node['label'] ?? 'Execution step' }}</p>
                                                            <p class="mt-1 text-xs text-textSecondary">{{ $node['description'] ?? '' }}</p>
                                                        </div>
                                                        <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) ($node['status'] ?? 'queued')) }}</span>
                                                    </div>
                                                    <div class="mt-3 flex flex-wrap gap-2 text-xs text-textSecondary">
                                                        <span>Stage: {{ str_replace('_', ' ', (string) ($node['stage'] ?? 'execution')) }}</span>
                                                        @if (! empty($node['depends_on']))
                                                            <span>Depends on: {{ implode(', ', (array) $node['depends_on']) }}</span>
                                                        @endif
                                                        @if (! empty($node['produces']))
                                                            <span>Produces: {{ implode(', ', (array) $node['produces']) }}</span>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="rounded-md border border-border bg-background p-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Dependencies</p>
                                        <div class="mt-3 grid gap-2 text-xs text-textSecondary sm:grid-cols-2">
                                            @foreach ($edges as $edge)
                                                <div class="rounded border border-border bg-surface px-2.5 py-2">{{ $edge['from'] ?? '' }} -> {{ $edge['to'] ?? '' }}</div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @elseif ($asset->type === 'content_diff_preview')
                                @php
                                    $payload = (array) $asset->payload;
                                    $highlights = (array) data_get($payload, 'highlights', []);
                                    $beforeLines = (array) data_get($payload, 'before.preview_lines', []);
                                    $afterLines = (array) data_get($payload, 'after.preview_lines', []);
                                    $diffLines = (array) data_get($payload, 'diff.lines', []);
                                @endphp

                                <div class="mt-4 space-y-4">
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($highlights as $highlight)
                                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800">{{ str_replace('_', ' ', (string) ($highlight['label'] ?? $highlight['type'] ?? 'Change')) }}</span>
                                        @endforeach
                                    </div>

                                    <div class="grid gap-4 lg:grid-cols-2">
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Before</p>
                                            <div class="mt-3 max-h-72 overflow-auto space-y-2 text-sm text-textSecondary">
                                                @forelse ($beforeLines as $line)
                                                    <p>{{ $line }}</p>
                                                @empty
                                                    <p>No existing content.</p>
                                                @endforelse
                                            </div>
                                        </div>
                                        <div class="rounded-md border border-emerald-200 bg-emerald-50/40 p-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">After</p>
                                            <div class="mt-3 max-h-72 overflow-auto space-y-2 text-sm text-textPrimary">
                                                @foreach ($afterLines as $line)
                                                    <p>{{ $line }}</p>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-md border border-border bg-background p-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">AI content git diff</p>
                                        <div class="mt-3 max-h-80 overflow-auto font-mono text-xs">
                                            @foreach ($diffLines as $line)
                                                @php $type = (string) ($line['type'] ?? 'context'); @endphp
                                                <div class="@class([
                                                    'whitespace-pre-wrap px-2 py-0.5',
                                                    'bg-emerald-50 text-emerald-900' => $type === 'added',
                                                    'bg-rose-50 text-rose-900 line-through' => $type === 'removed',
                                                    'text-textSecondary' => $type === 'context',
                                                ])">{{ $type === 'added' ? '+ ' : ($type === 'removed' ? '- ' : '  ') }}{{ $line['text'] ?? '' }}</div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @else
                                <pre class="mt-4 max-h-72 overflow-auto rounded-md border border-border bg-background p-3 text-xs text-textSecondary">{{ json_encode($asset->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            @endif

                            @if ($asset->status === 'generated')
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('app.agentic-marketing.execution-assets.approve', $asset) }}">
                                        @csrf
                                        <button class="pl-btn-primary"><i data-lucide="check" class="h-4 w-4"></i><span>Approve</span></button>
                                    </form>
                                    <form method="POST" action="{{ route('app.agentic-marketing.execution-assets.reject', $asset) }}" class="flex gap-2">
                                        @csrf
                                        <input name="feedback" class="pl-input text-sm" placeholder="Change request">
                                        <button class="pl-btn-ghost"><i data-lucide="x" class="h-4 w-4"></i><span>Request changes</span></button>
                                    </form>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>

                <aside class="space-y-4">
                    <div class="rounded-lg border border-border bg-surface p-4">
                        <h2 class="text-sm font-semibold text-textPrimary">Reviewer feedback</h2>
                        <form method="POST" action="{{ route('app.agentic-marketing.execution-pipelines.feedback', $pipeline) }}" class="mt-3 space-y-3">
                            @csrf
                            <textarea name="body" rows="4" required class="pl-input w-full text-sm" placeholder="Add review notes"></textarea>
                            <button class="pl-btn-ghost">Add feedback</button>
                        </form>
                        <div class="mt-4 space-y-2 text-xs text-textSecondary">
                            @forelse ($pipeline->feedback as $item)
                                <div class="rounded-md border border-border bg-background p-3">
                                    <p>{{ $item->body }}</p>
                                    <p class="mt-1">{{ $item->created_at?->format('Y-m-d H:i') }}</p>
                                </div>
                            @empty
                                <p>No feedback yet.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="rounded-lg border border-border bg-surface p-4">
                        <h2 class="text-sm font-semibold text-textPrimary">Rollback and retry</h2>
                        <p class="mt-1 text-xs text-textSecondary">Retry creates a fresh pipeline from the same opportunity while preserving this pipeline’s audit trail.</p>
                        <form method="POST" action="{{ route('app.agentic-marketing.execution-pipelines.retry', $pipeline) }}" class="mt-3">
                            @csrf
                            <button class="pl-btn-ghost"><i data-lucide="rotate-cw" class="h-4 w-4"></i><span>Retry pipeline</span></button>
                        </form>
                    </div>

                    <div class="rounded-lg border border-border bg-surface p-4">
                        <h2 class="text-sm font-semibold text-textPrimary">Execution audit</h2>
                        <div class="mt-3 space-y-2 text-xs text-textSecondary">
                            @foreach ($pipeline->auditLogs->sortByDesc('created_at')->take(12) as $log)
                                <div class="rounded-md border border-border bg-background p-3">
                                    <p class="font-medium text-textPrimary">{{ str_replace('_', ' ', $log->event) }}</p>
                                    <p class="mt-1">{{ $log->created_at?->format('Y-m-d H:i') }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </aside>
            </section>
        @endif
    </div>
@endsection
