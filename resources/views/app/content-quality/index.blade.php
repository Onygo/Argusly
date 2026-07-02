@extends('layouts.app', ['title' => 'Content Intelligence'])

@section('pageHeader')
    <x-page-header title="Content Intelligence">
        <x-slot:description>Review quality, originality, structure, and improvement signals across workspace content.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-textFaint">{{ $workspace->display_name ?: $workspace->name }}</p>
                <h2 class="text-2xl font-semibold tracking-tight text-textPrimary">Content Intelligence</h2>
                <p class="mt-1 max-w-3xl text-sm text-textSecondary">Review AI readiness, GEO signals, source coverage, structure, and duplicate-title opportunities for this workspace only.</p>
            </div>
        </div>

        <section class="rounded-lg border border-border bg-surface p-4">
            <form method="POST" action="{{ route('app.workspaces.content-quality.run', $workspace) }}" class="grid gap-4 lg:grid-cols-[1.4fr_repeat(5,minmax(0,1fr))_auto] lg:items-end">
                @csrf
                <label class="flex items-start gap-3 rounded-md border border-border bg-background px-3 py-3 text-sm">
                    <input type="hidden" name="published_only" value="0">
                    <input class="mt-1" type="checkbox" name="published_only" value="1" @checked((bool) ($filters['published_only'] ?? true)) @disabled(! $canRun)>
                    <span>
                        <span class="block font-medium text-textPrimary">Published only</span>
                        <span class="mt-1 block text-xs text-textSecondary">Focus on live content that affects search and AI answer visibility.</span>
                    </span>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-textSecondary">Type</span>
                    <select name="content_type" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary" @disabled(! $canRun)>
                        @foreach (['article' => 'Articles', 'page' => 'Pages', 'post' => 'Posts'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['content_type'] ?? 'article') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-textSecondary">Issue</span>
                    <select name="issue_type" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary" @disabled(! $canRun)>
                        <option value="">All issues</option>
                        @foreach ([
                            'ai_readiness' => 'AI readiness',
                            'duplicate_titles' => 'Duplicate titles',
                            'headings' => 'Headings',
                            'sources' => 'Sources',
                            'links' => 'Links',
                            'depth' => 'Depth',
                            'freshness' => 'Freshness',
                            'structure' => 'Structure',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['issue_type'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-textSecondary">Severity</span>
                    <select name="severity" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary" @disabled(! $canRun)>
                        <option value="">All</option>
                        @foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['severity'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-textSecondary">Locale</span>
                    <input type="text" name="locale" maxlength="12" value="{{ (string) ($filters['locale'] ?? '') }}" placeholder="All" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary" @disabled(! $canRun)>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-textSecondary">Limit</span>
                    <input type="number" name="limit" min="1" max="1000" value="{{ (int) ($filters['limit'] ?? 500) }}" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary" @disabled(! $canRun)>
                </label>

                <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-border bg-textPrimary px-4 text-sm font-medium text-white hover:bg-textPrimary/90 disabled:cursor-not-allowed disabled:opacity-50" @disabled(! $canRun)>
                    <i data-lucide="scan-search" class="h-4 w-4"></i>
                    Run audit
                </button>
            </form>

            @unless ($canRun)
                <p class="mt-3 text-sm text-textSecondary">You can view Content Intelligence, but your role cannot trigger new audits.</p>
            @endunless
        </section>

        @if (is_array($result))
            @php
                $summary = (array) ($result['summary'] ?? []);
                $items = collect((array) ($result['items'] ?? []));
            @endphp

            <section class="grid gap-4 md:grid-cols-5">
                @foreach ([
                    'Audited' => (int) ($summary['audited'] ?? 0),
                    'With issues' => (int) ($summary['with_issues'] ?? 0),
                    'Total issues' => (int) ($summary['issues'] ?? 0),
                    'Easy wins' => (int) ($summary['easy_wins'] ?? 0),
                    'High impact' => (int) ($summary['high_impact'] ?? 0),
                ] as $label => $value)
                    <div class="rounded-lg border border-border bg-surface p-4">
                        <p class="text-xs text-textSecondary">{{ $label }}</p>
                        <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ $value }}</p>
                    </div>
                @endforeach
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">High impact opportunities</h2>
                    <ul class="mt-3 space-y-2 text-sm text-textSecondary">
                        @forelse ($items->where('severity', 'high')->take(5) as $item)
                            <li class="flex items-start justify-between gap-3">
                                <span>{{ $item['title'] ?? 'Untitled' }}</span>
                                <span class="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700">{{ (int) ($item['issue_count'] ?? 0) }} issues</span>
                            </li>
                        @empty
                            <li>No high impact opportunities in this run.</li>
                        @endforelse
                    </ul>
                </div>
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Easy wins</h2>
                    <ul class="mt-3 space-y-2 text-sm text-textSecondary">
                        @forelse ($items->where('impact', 'easy_win')->take(5) as $item)
                            <li>{{ $item['title'] ?? 'Untitled' }}</li>
                        @empty
                            <li>No easy wins in this run.</li>
                        @endforelse
                    </ul>
                </div>
            </section>

            <section class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-4 py-3">
                    <h2 class="text-sm font-semibold text-textPrimary">Optimization queue</h2>
                    <p class="mt-1 text-xs text-textSecondary">Scoped to this workspace. Duplicate-title checks compare content in the same workspace and site.</p>
                </div>

                <x-responsive-table table-class="text-sm">
                    <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-textSecondary">
                        <th class="px-4 py-3 font-medium">Content</th>
                        <th class="px-4 py-3 font-medium">Signals</th>
                        <th class="px-4 py-3 font-medium">Issues</th>
                        <th class="px-4 py-3 font-medium">Actions</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                    @forelse ($items as $item)
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <div class="font-medium text-textPrimary">{{ $item['title'] ?? 'Untitled' }}</div>
                                <div class="mt-1 text-xs text-textSecondary">{{ strtoupper((string) ($item['locale'] ?? '-')) }} · {{ $item['site_name'] ?: 'No site' }} · {{ (int) ($item['word_count'] ?? 0) }} words</div>
                                <div class="mt-1 font-mono text-[11px] text-textFaint">{{ $item['id'] ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ ($item['severity'] ?? '') === 'high' ? 'bg-rose-50 text-rose-700' : (($item['severity'] ?? '') === 'medium' ? 'bg-amber-50 text-amber-800' : 'bg-surfaceMuted text-textSecondary') }}">{{ ucfirst((string) ($item['severity'] ?? 'low')) }}</span>
                                @if (! empty($item['duplicate_title_matches'] ?? []))
                                    @php $titleRiskCount = count((array) ($item['duplicate_title_matches'] ?? [])); @endphp
                                    <div class="mt-2 text-xs text-amber-800">{{ $titleRiskCount }} title risk{{ $titleRiskCount === 1 ? '' : 's' }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="mb-2 text-xs font-medium text-textSecondary">{{ (int) ($item['issue_count'] ?? 0) }} issue(s)</div>
                                <ul class="space-y-1 text-xs text-textSecondary">
                                    @foreach ((array) ($item['issues'] ?? []) as $issue)
                                        <li>{{ $issue }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('app.content.show', $item['id']) }}" class="inline-flex h-8 items-center rounded-md border border-border px-2 text-xs font-medium text-textSecondary hover:bg-surfaceMuted">Open</a>
                                    <button type="button" disabled title="Generate answer block hook is prepared for a future action service." class="inline-flex h-8 items-center rounded-md border border-border px-2 text-xs font-medium text-textFaint">Answer block</button>
                                    <button type="button" disabled title="Heading and source suggestion actions will attach here." class="inline-flex h-8 items-center rounded-md border border-border px-2 text-xs font-medium text-textFaint">Optimize</button>
                                    <button type="button" disabled title="Ignore state is not stored yet." class="inline-flex h-8 items-center rounded-md border border-border px-2 text-xs font-medium text-textFaint">Ignore</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-textSecondary">No content intelligence issues found for this selection.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </x-responsive-table>
            </section>
        @endif
    </div>
@endsection
