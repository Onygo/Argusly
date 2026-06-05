@extends('layouts.app', ['title' => 'Learnings'])

@section('content')
    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            title="Learnings"
            description="Review content performance, engagement patterns, and AI SEO signals from tracked traffic."
            active="learnings"
        >
            <form method="GET" class="flex flex-wrap gap-2">
                <select name="days" onchange="this.form.submit()" class="rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="7" {{ $days == 7 ? 'selected' : '' }}>Last 7 days</option>
                    <option value="14" {{ $days == 14 ? 'selected' : '' }}>Last 14 days</option>
                    <option value="30" {{ $days == 30 ? 'selected' : '' }}>Last 30 days</option>
                    <option value="90" {{ $days == 90 ? 'selected' : '' }}>Last 90 days</option>
                </select>
                <select name="scope" onchange="this.form.submit()" class="rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="all" {{ $scope === 'all' ? 'selected' : '' }}>All pages</option>
                    <option value="publishlayer_content" {{ $scope === 'publishlayer_content' ? 'selected' : '' }}>PublishLayer content</option>
                    <option value="other_page" {{ $scope === 'other_page' ? 'selected' : '' }}>Other site pages</option>
                </select>
                <select name="sort" onchange="this.form.submit()" class="rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="views" {{ ($sort ?? 'views') === 'views' ? 'selected' : '' }}>Sort: Views</option>
                    <option value="roi_score" {{ ($sort ?? 'views') === 'roi_score' ? 'selected' : '' }}>Sort: Content ROI</option>
                    <option value="ai_seo_score" {{ ($sort ?? 'views') === 'ai_seo_score' ? 'selected' : '' }}>Sort: AI SEO Score</option>
                </select>
            </form>
            <a href="{{ route('app.sites.show', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Site setup</a>
        </x-app.insights-header>

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-lg border border-border bg-surface p-6">
            <p class="text-sm text-textSecondary">Page Views</p>
            <p class="text-2xl font-semibold text-textPrimary">{{ number_format($summary['total_views']) }}</p>
        </div>
            <div class="rounded-lg border border-border bg-surface p-6">
            <p class="text-sm text-textSecondary">Unique Visitors</p>
            <p class="text-2xl font-semibold text-textPrimary">{{ number_format($summary['total_uniques']) }}</p>
        </div>
            <div class="rounded-lg border border-border bg-surface p-6">
            <p class="text-sm text-textSecondary">Engaged</p>
            <p class="text-2xl font-semibold text-textPrimary">{{ number_format($summary['total_engaged']) }}</p>
        </div>
            <div class="rounded-lg border border-border bg-surface p-6">
            <p class="text-sm text-textSecondary">Read-through</p>
            <p class="text-2xl font-semibold text-textPrimary">{{ number_format($summary['total_read_through']) }}</p>
        </div>
            <div class="rounded-lg border border-border bg-surface p-6">
            <p class="text-sm text-textSecondary">Engagement Rate</p>
            <p class="text-2xl font-semibold text-textPrimary">{{ $summary['engagement_rate'] }}%</p>
        </div>
        </div>
        <p class="text-xs text-textSecondary">
            Engaged means visitors stayed active for at least {{ $metricThresholds['engaged_after_seconds'] }} seconds or interacted.
            Read-through counts visitors who reached {{ $metricThresholds['read_through_scroll_percent'] }}% scroll depth or stayed at least {{ $metricThresholds['read_through_fallback_seconds'] }} seconds on short pages.
        </p>

        <div class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border p-6">
            <h2 class="font-semibold text-textPrimary">Trending Content</h2>
            <p class="text-sm text-textSecondary">{{ $summary['unique_pages'] }} pages tracked</p>
        </div>

        @if ($trending->isEmpty())
                @php($isInternallyVerified = $analyticsSite?->isInternallyVerified() ?? false)
                <div class="p-6 py-12 text-center">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-accentYellow-100">
                    <i data-lucide="bar-chart-3" class="h-8 w-8 text-accentYellow-900"></i>
                </div>
                <h3 class="text-lg font-semibold text-textPrimary">No analytics data yet</h3>
                <p class="mt-2 max-w-md mx-auto text-textSecondary">
                    @if ($isInternallyVerified)
                        Tracking is automatically injected for this first-party domain. Data will appear after the next public page view is received.
                    @else
                        We haven't received any page views yet. Make sure the tracking script is properly installed on your website.
                    @endif
                </p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    <a href="{{ route('app.sites.analytics.show', $site) }}" class="inline-flex items-center gap-2 rounded bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">
                        <i data-lucide="code" class="h-4 w-4"></i>
                        {{ $isInternallyVerified ? 'View analytics setup' : 'Install tracking script' }}
                    </a>
                    <a href="{{ route('app.sites.show', $site) }}" class="inline-flex items-center gap-2 rounded border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                        <i data-lucide="settings" class="h-4 w-4"></i>
                        Site settings
                    </a>
                </div>
                <p class="mt-6 text-xs text-textSecondary">
                    @if ($isInternallyVerified)
                        First-party analytics usually appears within a few minutes of the first tracked page view.
                    @else
                        After installation, data will appear within a few minutes of your first page view.
                    @endif
                </p>
                </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-textSecondary">
                            <th class="px-4 py-3 font-medium">Page</th>
                            <th class="px-4 py-3 font-medium text-right">Views</th>
                            <th class="px-4 py-3 font-medium text-right">Uniques</th>
                            <th class="px-4 py-3 font-medium text-right">Engaged</th>
                            <th class="px-4 py-3 font-medium text-right">Read-through</th>
                            <th class="px-4 py-3 font-medium text-right">Avg Scroll</th>
                            <th class="px-4 py-3 font-medium text-right">Avg Read</th>
                            <th class="px-4 py-3 font-medium text-right">Content ROI</th>
                            <th class="px-4 py-3 font-medium text-right">AI Visibility</th>
                            <th class="px-4 py-3 font-medium text-right" title="{{ $aiSeoScoreFormulaLabel ?? 'AI SEO Score' }}">AI SEO Score</th>
                            <th class="px-4 py-3 font-medium text-right">Eng. Rate</th>
                            <th class="px-4 py-3 font-medium">Last Seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($trending as $page)
                            <tr class="hover:bg-surfaceSubtle">
                                <td class="px-4 py-3">
                                    <div class="max-w-xs truncate font-medium text-textPrimary" title="{{ $page['path'] }}">
                                        {{ $page['title'] }}
                                    </div>
                                    <div class="max-w-xs truncate text-xs text-textSecondary">{{ $page['path'] }}</div>
                                </td>
                                <td class="px-4 py-3 text-right text-textPrimary">{{ number_format($page['views']) }}</td>
                                <td class="px-4 py-3 text-right text-textPrimary">{{ number_format($page['uniques']) }}</td>
                                <td class="px-4 py-3 text-right text-textPrimary">{{ number_format($page['engaged']) }}</td>
                                <td class="px-4 py-3 text-right text-textPrimary">{{ number_format($page['read_through']) }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($page['avg_scroll_depth'] === null)
                                        <span class="text-textSecondary" title="Not enough data yet">—</span>
                                    @else
                                        <span class="@if($page['avg_scroll_depth'] < 40) text-amber-600 @elseif($page['avg_scroll_depth'] > 60) text-success @else text-textSecondary @endif">
                                            {{ number_format((float) $page['avg_scroll_depth'], 1) }}%
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-textPrimary">
                                    @if ($page['avg_read_time'] === null)
                                        <span class="text-textSecondary" title="Not enough data yet">—</span>
                                    @else
                                        {{ number_format((float) $page['avg_read_time'], 1) }}s
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($page['roi_score'] === null)
                                        <span class="text-textSecondary" title="Not enough data yet">—</span>
                                    @else
                                        <span class="@if($page['roi_score'] < 40) text-rose-600 @elseif($page['roi_score'] < 70) text-amber-600 @else text-success @endif">
                                            {{ number_format((float) $page['roi_score'], 1) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex items-center justify-end gap-1">
                                        @if ($page['ai_visibility_score'] === null)
                                            <span class="text-textSecondary" title="Not enough data yet">—</span>
                                        @else
                                            <span class="text-textPrimary">{{ number_format((float) $page['ai_visibility_score'], 1) }}</span>
                                        @endif
                                        @if ($page['is_ai_cited'])
                                            <span class="rounded bg-success/15 px-1.5 py-0.5 text-[10px] font-medium text-success">Cited</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($page['ai_seo_score'] === null)
                                        @if (($page['ai_seo_score_stale'] ?? false) === true)
                                            <span class="text-amber-600" title="Score is stale and will refresh after recalculation">Stale</span>
                                        @else
                                            <span class="text-textSecondary" title="Not enough data yet">—</span>
                                        @endif
                                    @else
                                        <span class="@if($page['ai_seo_score'] < 40) text-rose-600 @elseif($page['ai_seo_score'] < 70) text-amber-600 @else text-success @endif">
                                            {{ number_format((float) $page['ai_seo_score'], 1) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="@if($page['engagement_rate'] >= 50) text-success @elseif($page['engagement_rate'] >= 25) text-amber-600 @else text-textSecondary @endif">
                                        {{ $page['engagement_rate'] }}%
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-textSecondary">{{ $page['last_seen_label'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        </div>
    </div>
@endsection
