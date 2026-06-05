@extends('layouts.app', ['title' => 'Content Network Intelligence'])

@section('content')
    @php
        $clusterSummary = (array) ($summary['cluster_summary'] ?? []);
        $gaps = is_array($gaps ?? null) ? $gaps : [];
        $missingPillarPages = (array) ($gaps['missing_pillar_pages'] ?? []);
        $missingSupportArticles = (array) ($gaps['missing_support_articles'] ?? []);
        $suggestedMissingArticles = (array) ($gaps['suggested_missing_articles'] ?? []);
        $runStatus = (string) ($summary['status'] ?? 'idle');
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Content Network Intelligence</h1>
            <p class="mt-1 text-textSecondary">Analyze clusters, internal link opportunities, and missing supporting content.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" action="{{ route('app.content-network.index') }}" class="flex items-center gap-2">
                <select name="workspace_id" class="pl-select bg-surface" onchange="this.form.submit()">
                    @foreach ($workspaces as $item)
                        <option value="{{ $item->id }}" @selected((string) $item->id === (string) $workspace->id)>{{ $item->display_name }}</option>
                    @endforeach
                </select>
            </form>
            @if ($canRun)
                <form method="POST" action="{{ route('app.content-network.run', $workspace) }}">
                    @csrf
                    <button class="rounded border border-border bg-background px-3 py-2 text-sm">Run analysis</button>
                </form>
                <form method="POST" action="{{ route('app.content-network.run', $workspace) }}">
                    @csrf
                    <input type="hidden" name="force" value="1">
                    <button class="rounded border border-border px-3 py-2 text-sm">Rerun</button>
                </form>
            @endif
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="mb-4 rounded-lg border border-border bg-surface p-4">
        <div class="grid gap-3 text-sm md:grid-cols-4">
            <div>
                <p class="text-xs text-textSecondary">Run status</p>
                <p class="mt-1 font-medium text-textPrimary">{{ strtoupper($runStatus) }}</p>
            </div>
            <div>
                <p class="text-xs text-textSecondary">Published items</p>
                <p class="mt-1 font-medium text-textPrimary">{{ (int) ($clusterSummary['published_content_count'] ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-textSecondary">Topic clusters</p>
                <p class="mt-1 font-medium text-textPrimary">{{ (int) ($clusterSummary['cluster_count'] ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-textSecondary">Link opportunities</p>
                <p class="mt-1 font-medium text-textPrimary">{{ (int) ($summary['opportunities_count'] ?? 0) }}</p>
            </div>
        </div>
        <p class="mt-3 text-xs text-textSecondary">
            Last update: {{ !empty($summary['updated_at']) ? \Illuminate\Support\Carbon::parse($summary['updated_at'])->format('Y-m-d H:i') : '-' }}
        </p>
        @if (!empty($summary['failure_reason']))
            <p class="mt-2 text-xs text-rose-700">{{ $summary['failure_reason'] }}</p>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-lg border border-border bg-surface p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-textPrimary">Topic clusters</h2>
                    <span class="text-xs text-textSecondary">{{ $clusters->count() }} clusters</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-textSecondary">
                                <th class="pb-2 font-medium">Cluster</th>
                                <th class="pb-2 font-medium">Topic keyword</th>
                                <th class="pb-2 font-medium">Pillar candidate</th>
                                <th class="pb-2 font-medium">Related articles</th>
                                <th class="pb-2 font-medium">Cluster score</th>
                                <th class="pb-2 font-medium">Weak areas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($clusters as $cluster)
                                @php
                                    $relatedCount = (int) data_get($cluster->meta, 'related_article_count', collect((array) ($cluster->supporting_content_ids ?? []))->count() + ($cluster->pillar_content_id ? 1 : 0));
                                    $gapSummary = (array) data_get($cluster->meta, 'gap_summary', []);
                                    $weakLabels = [];
                                    if ((int) ($gapSummary['missing_pillar_count'] ?? 0) > 0) {
                                        $weakLabels[] = 'Missing pillar';
                                    }
                                    if ((int) ($gapSummary['missing_support_count'] ?? 0) > 0) {
                                        $weakLabels[] = 'Missing support';
                                    }
                                @endphp
                                <tr>
                                    <td class="py-2 text-textPrimary">{{ $cluster->name }}</td>
                                    <td class="py-2 text-textPrimary">{{ $cluster->topic_keyword }}</td>
                                    <td class="py-2 text-textPrimary">
                                        @if ($cluster->pillarContent)
                                            <a class="underline" href="{{ route('app.content.show', $cluster->pillarContent) }}">{{ $cluster->pillarContent->title }}</a>
                                        @else
                                            <span class="text-textSecondary">No pillar candidate</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-textPrimary">{{ $relatedCount }}</td>
                                    <td class="py-2 text-textPrimary">{{ number_format((float) ($cluster->cluster_score ?? 0), 1) }}</td>
                                    <td class="py-2 text-textSecondary">{{ $weakLabels !== [] ? implode(', ', $weakLabels) : '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-4 text-textSecondary">No clusters yet. Run analysis to generate the first content map.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-textPrimary">Link opportunities</h2>
                    <span class="text-xs text-textSecondary">{{ $opportunities->total() }} opportunities</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-textSecondary">
                                <th class="pb-2 font-medium">Source article</th>
                                <th class="pb-2 font-medium">Target article</th>
                                <th class="pb-2 font-medium">Suggested anchor</th>
                                <th class="pb-2 font-medium">Relevance score</th>
                                <th class="pb-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($opportunities as $opportunity)
                                <tr>
                                    <td class="py-2">
                                        @if ($opportunity->sourceContent)
                                            <a class="underline text-textPrimary" href="{{ route('app.content.show', $opportunity->sourceContent) }}">{{ $opportunity->sourceContent->title }}</a>
                                        @else
                                            <span class="text-textSecondary">Deleted content</span>
                                        @endif
                                    </td>
                                    <td class="py-2">
                                        @if ($opportunity->targetContent)
                                            <a class="underline text-textPrimary" href="{{ route('app.content.show', $opportunity->targetContent) }}">{{ $opportunity->targetContent->title }}</a>
                                        @else
                                            <span class="text-textSecondary">Deleted content</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-textPrimary">{{ $opportunity->anchor_text_suggestion ?: '-' }}</td>
                                    <td class="py-2 text-textPrimary">{{ number_format((float) ($opportunity->relevance_score ?? 0), 1) }}</td>
                                    <td class="py-2 text-textSecondary">{{ strtoupper((string) $opportunity->status) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-textSecondary">No link opportunities available yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $opportunities->links() }}</div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-lg border border-border bg-surface p-4">
                <h3 class="text-sm font-semibold text-textPrimary">Weak or orphan content</h3>
                <div class="mt-3">
                    <p class="text-xs font-medium text-textPrimary">Orphan content</p>
                    @if ($orphanContent->isNotEmpty())
                        <ul class="mt-1 list-disc pl-4 text-xs text-textSecondary">
                            @foreach ($orphanContent as $content)
                                <li><a class="underline" href="{{ route('app.content.show', $content) }}">{{ $content->title }}</a></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-1 text-xs text-textSecondary">No orphan items detected.</p>
                    @endif
                </div>
                <div class="mt-3">
                    <p class="text-xs font-medium text-textPrimary">Weakly connected content</p>
                    @if ($weakContent->isNotEmpty())
                        <ul class="mt-1 list-disc pl-4 text-xs text-textSecondary">
                            @foreach ($weakContent as $content)
                                <li><a class="underline" href="{{ route('app.content.show', $content) }}">{{ $content->title }}</a></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-1 text-xs text-textSecondary">No weakly connected items detected.</p>
                    @endif
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4">
                <h3 class="text-sm font-semibold text-textPrimary">Content gaps</h3>
                <div class="mt-3 space-y-3 text-xs text-textSecondary">
                    <div>
                        <p class="font-medium text-textPrimary">Suggested missing pillar pages</p>
                        @if ($missingPillarPages !== [])
                            <ul class="mt-1 list-disc pl-4">
                                @foreach (array_slice($missingPillarPages, 0, 8) as $item)
                                    <li>{{ $item['suggested_title'] ?? '-' }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1">No missing pillar opportunities detected.</p>
                        @endif
                    </div>

                    <div>
                        <p class="font-medium text-textPrimary">Suggested support articles</p>
                        @if ($missingSupportArticles !== [])
                            <ul class="mt-1 list-disc pl-4">
                                @foreach (array_slice($missingSupportArticles, 0, 8) as $item)
                                    <li>{{ $item['suggested_title'] ?? '-' }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1">No support article gaps detected.</p>
                        @endif
                    </div>

                    <div>
                        <p class="font-medium text-textPrimary">Next article suggestions</p>
                        @if ($suggestedMissingArticles !== [])
                            <ul class="mt-1 list-disc pl-4">
                                @foreach (array_slice($suggestedMissingArticles, 0, 10) as $item)
                                    <li>{{ $item['suggested_title'] ?? '-' }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1">No additional gap suggestions available.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
