@extends('layouts.app', ['title' => 'Learning Optimization', 'pageWidth' => 'wide'])

@section('content')
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Learning Optimization</h1>
            <p class="mt-1 text-sm text-textSecondary">Performance learning across content, campaigns, LinkedIn, AI visibility, CTA, hooks, tone, topics, and conversions.</p>
        </div>
        <form method="POST" action="{{ route('app.agentic-marketing.learning.run', request()->query()) }}" class="flex gap-2">
            @csrf
            <button class="pl-btn-primary" type="submit">
                <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                <span>Refresh learning</span>
            </button>
            <button class="pl-btn-ghost" name="run_inline" value="1" type="submit">Run inline</button>
        </form>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @php
        $recommendationActionMeta = function ($recommendation) use ($workspace): array {
            $type = (string) ($recommendation->type?->value ?? $recommendation->type);
            $contentUrl = $recommendation->content_id ? route('app.content.show', $recommendation->content_id) : null;
            $campaignUrl = $recommendation->campaign_id
                ? route('app.agentic-marketing.campaign-planner.index', ['campaign' => $recommendation->campaign_id, 'workspace_id' => $workspace->id])
                : null;

            return match ($type) {
                'repost' => [
                    'label' => 'Create LinkedIn repost',
                    'url' => route('app.agentic-marketing.distribution.index', ['workspace_id' => $workspace->id]),
                    'icon' => 'repeat-2',
                    'next' => 'Turn the winning hook into a fresh LinkedIn variant and schedule it after the original post cools down.',
                ],
                'refresh' => [
                    'label' => 'Open content to refresh',
                    'url' => $contentUrl,
                    'icon' => 'file-pen-line',
                    'next' => 'Update stale sections, add internal links, and improve answer coverage before republishing.',
                ],
                'ai_visibility' => [
                    'label' => 'Improve AI visibility',
                    'url' => $contentUrl,
                    'icon' => 'sparkles',
                    'next' => 'Add answer-first sections, strengthen entity coverage, and link related supporting pages.',
                ],
                'hook_optimization' => [
                    'label' => 'Test LinkedIn hooks',
                    'url' => route('app.agentic-marketing.distribution.index', ['workspace_id' => $workspace->id]),
                    'icon' => 'message-square-text',
                    'next' => 'Create two alternate openings: one contrast hook and one practical how-to hook.',
                ],
                'campaign_expansion' => [
                    'label' => 'Open campaign plan',
                    'url' => $campaignUrl,
                    'icon' => 'network',
                    'next' => 'Add one supporting article, one LinkedIn variant, and one recap asset around the strongest topic.',
                ],
                default => [
                    'label' => $contentUrl ? 'Open content' : ($campaignUrl ? 'Open campaign' : 'Review recommendation'),
                    'url' => $contentUrl ?: $campaignUrl,
                    'icon' => 'arrow-right',
                    'next' => (string) collect((array) $recommendation->recommended_actions)->first() ?: 'Review the evidence and turn it into one concrete follow-up task.',
                ],
            };
        };

        $nextRecommendations = $recommendations->take(4);
    @endphp

    <div class="mb-6 grid gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Avg Content Score</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((float) $summary['avg_content_score'], 1) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Avg Campaign Score</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((float) $summary['avg_campaign_score'], 1) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Recommendations</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((int) $summary['recommendations']) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">AI Visibility Avg</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((float) $summary['ai_visibility_avg'], 1) }}</p>
        </div>
    </div>

    <section class="mb-6 rounded-lg border border-border bg-surface">
        <div class="border-b border-border px-5 py-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">Do this next</h2>
                    <p class="mt-1 text-xs text-textSecondary">The highest-priority learning recommendations translated into immediate next steps.</p>
                </div>
                <a href="#recommendation-center" class="inline-flex items-center gap-2 text-sm font-medium text-link hover:text-linkHover">
                    <span>Review all recommendations</span>
                    <i data-lucide="arrow-down" class="h-4 w-4"></i>
                </a>
            </div>
        </div>
        <div class="grid gap-0 divide-y divide-border lg:grid-cols-4 lg:divide-x lg:divide-y-0">
            @forelse ($nextRecommendations as $recommendation)
                @php($actionMeta = $recommendationActionMeta($recommendation))
                <article class="flex min-h-full flex-col p-5">
                    <div class="flex items-start justify-between gap-3">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primarySoftBg text-sm font-semibold text-primary">{{ $loop->iteration }}</span>
                        <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">Priority {{ number_format((float) $recommendation->priority_score, 0) }}</span>
                    </div>
                    <p class="mt-4 text-sm font-semibold text-textPrimary">{{ $recommendation->title }}</p>
                    <p class="mt-2 text-sm text-textSecondary">{{ $actionMeta['next'] }}</p>
                    <p class="mt-3 text-xs text-textFaint">
                        {{ $recommendation->content?->title ?? $recommendation->campaign?->name ?? 'Workspace-level recommendation' }}
                    </p>
                    <div class="mt-4">
                        @if ($actionMeta['url'])
                            <a href="{{ $actionMeta['url'] }}" class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                <i data-lucide="{{ $actionMeta['icon'] }}" class="h-4 w-4"></i>
                                <span>{{ $actionMeta['label'] }}</span>
                            </a>
                        @else
                            <span class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium text-textSecondary">
                                <i data-lucide="{{ $actionMeta['icon'] }}" class="h-4 w-4"></i>
                                <span>{{ $actionMeta['label'] }}</span>
                            </span>
                        @endif
                    </div>
                </article>
            @empty
                <p class="p-5 text-sm text-textSecondary lg:col-span-4">No immediate next steps yet. Refresh learning after campaigns and content collect performance signals.</p>
            @endforelse
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[1.4fr_1fr]">
        <section id="recommendation-center" class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-sm font-semibold text-textPrimary">Recommendation Center</h2>
                <p class="mt-1 text-xs text-textSecondary">Each recommendation shows the action, the reason, and the expected outcome.</p>
            </div>
            <div class="divide-y divide-border">
                @forelse ($recommendations as $recommendation)
                    @php($actionMeta = $recommendationActionMeta($recommendation))
                    <article class="p-5">
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ $recommendation->type?->label() ?? str_replace('_', ' ', (string) $recommendation->type) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">Priority {{ number_format((float) $recommendation->priority_score, 0) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">Confidence {{ number_format((float) $recommendation->confidence_score, 0) }}</span>
                                </div>
                                <h3 class="mt-2 font-semibold text-textPrimary">{{ $recommendation->title }}</h3>
                                <p class="mt-1 text-sm text-textSecondary">{{ $recommendation->summary }}</p>
                                <p class="mt-2 text-xs text-textSecondary">
                                    {{ $recommendation->content?->title ?? $recommendation->campaign?->name ?? 'Workspace-level recommendation' }}
                                </p>
                            </div>
                            @if ($actionMeta['url'])
                                <a href="{{ $actionMeta['url'] }}" class="inline-flex shrink-0 items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                    <i data-lucide="{{ $actionMeta['icon'] }}" class="h-4 w-4"></i>
                                    <span>{{ $actionMeta['label'] }}</span>
                                </a>
                            @endif
                        </div>
                        <div class="mt-4 rounded-md border border-primary/20 bg-primarySoftBg p-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-primary">Start here</p>
                            <p class="mt-1 text-sm font-medium text-textPrimary">{{ $actionMeta['next'] }}</p>
                        </div>
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Why</p>
                                <p class="mt-1 text-sm text-textPrimary">{{ data_get($recommendation->explanation, 'reason', 'Generated from stored learning evidence.') }}</p>
                            </div>
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Expected Impact</p>
                                <p class="mt-1 text-sm text-textPrimary">{{ data_get($recommendation->expected_impact, 'impact', 'Improve future campaign decisions.') }}</p>
                            </div>
                        </div>
                        <div class="mt-4 rounded-md border border-border bg-background p-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Action checklist</p>
                            <ol class="mt-2 space-y-2 text-sm text-textPrimary">
                            @foreach ((array) $recommendation->recommended_actions as $action)
                                <li class="flex gap-2">
                                    <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded bg-surfaceSubtle text-xs text-textSecondary">{{ $loop->iteration }}</span>
                                    <span>{{ $action }}</span>
                                </li>
                            @endforeach
                            </ol>
                        </div>
                    </article>
                @empty
                    <p class="p-6 text-sm text-textSecondary">No learning recommendations yet. Refresh learning after campaigns and content collect performance signals.</p>
                @endforelse
            </div>
        </section>

        <section class="space-y-6">
            <div class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">AI Visibility Analytics</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($aiVisibility as $row)
                        @php($trend = (array) $row->ai_visibility_trend)
                        <div class="rounded-md border border-border bg-background p-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="truncate text-sm font-medium text-textPrimary">{{ $row->primary_topic ?: $row->content_id }}</p>
                                <span class="text-sm font-semibold text-textPrimary">{{ number_format((float) $row->ai_visibility_score, 0) }}</span>
                            </div>
                            <p class="mt-1 text-xs text-textSecondary">
                                Citations {{ data_get($trend, 'trend.latest_citations', data_get($trend, 'latest_citations', 0)) }}
                                · Delta {{ data_get($trend, 'trend.score_delta', data_get($trend, 'score_delta', 0)) }}
                            </p>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No AI visibility learning profiles yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">Topic Performance</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($topicPerformance as $topic)
                        <div class="rounded-md border border-border bg-background p-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="truncate text-sm font-medium text-textPrimary">{{ $topic['topic'] }}</p>
                                <span class="text-sm font-semibold text-textPrimary">{{ number_format((float) $topic['avg_performance'], 0) }}</span>
                            </div>
                            <div class="mt-2 grid grid-cols-3 gap-2 text-xs text-textSecondary">
                                <span>Topic {{ $topic['avg_topic'] }}</span>
                                <span>LinkedIn {{ $topic['avg_linkedin'] }}</span>
                                <span>AI {{ $topic['avg_ai_visibility'] }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No topic performance yet.</p>
                    @endforelse
                </div>
            </div>
        </section>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-sm font-semibold text-textPrimary">Content Intelligence Graph</h2>
            </div>
            <div class="divide-y divide-border">
                @forelse ($contentProfiles as $profile)
                    <div class="grid gap-3 px-5 py-4 md:grid-cols-[1fr_auto]">
                        <div>
                            <p class="font-medium text-textPrimary">{{ $profile->content?->title ?? $profile->primary_topic }}</p>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs text-textSecondary">
                                <span>Article {{ number_format((float) $profile->article_score, 0) }}</span>
                                <span>LinkedIn {{ number_format((float) $profile->linkedin_score, 0) }}</span>
                                <span>CTA {{ number_format((float) $profile->cta_score, 0) }}</span>
                                <span>Hook {{ number_format((float) $profile->hook_score, 0) }}</span>
                                <span>Tone {{ number_format((float) $profile->tone_score, 0) }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-semibold text-textPrimary">{{ number_format((float) $profile->performance_score, 0) }}</p>
                            <p class="text-xs text-textSecondary">{{ optional($profile->analyzed_at)->diffForHumans() }}</p>
                        </div>
                    </div>
                @empty
                    <p class="p-6 text-sm text-textSecondary">No content learning profiles yet.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-sm font-semibold text-textPrimary">Campaign Learning Profiles</h2>
            </div>
            <div class="divide-y divide-border">
                @forelse ($campaignProfiles as $profile)
                    <div class="grid gap-3 px-5 py-4 md:grid-cols-[1fr_auto]">
                        <div>
                            <p class="font-medium text-textPrimary">{{ $profile->campaign?->name ?? 'Campaign' }}</p>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs text-textSecondary">
                                <span>Content {{ number_format((float) $profile->content_score, 0) }}</span>
                                <span>Distribution {{ number_format((float) $profile->distribution_score, 0) }}</span>
                                <span>AI {{ number_format((float) $profile->ai_visibility_score, 0) }}</span>
                                <span>Conversion {{ number_format((float) $profile->conversion_score, 0) }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-semibold text-textPrimary">{{ number_format((float) $profile->performance_score, 0) }}</p>
                            <p class="text-xs text-textSecondary">{{ optional($profile->analyzed_at)->diffForHumans() }}</p>
                        </div>
                    </div>
                @empty
                    <p class="p-6 text-sm text-textSecondary">No campaign learning profiles yet.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
