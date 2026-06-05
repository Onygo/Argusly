@extends('layouts.app', ['title' => 'SEO Audits'])

@section('content')
    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            title="Audits"
            description="Run SEO audits, review crawl history, and inspect issue counts for this site."
            active="audits"
        >
            <a href="{{ route('app.insights.index') }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">All sites</a>
            <a href="{{ route('app.sites.show', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Site setup</a>
        </x-app.insights-header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <div class="grid gap-6 md:grid-cols-3">
            <div class="rounded-lg border border-border bg-surface p-6">
            <p class="text-xs text-textSecondary">Monthly page cap</p>
            <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $auditPageLimit < 0 ? 'Unlimited' : $auditPageLimit }}</p>
            <p class="mt-1 text-xs text-textSecondary">Used this month: {{ $auditPagesUsed }}</p>
        </div>
            <div class="rounded-lg border border-border bg-surface p-6 md:col-span-2">
            <p class="text-xs text-textSecondary">Last run</p>
            @if ($lastAudit)
                <p class="mt-1 text-sm text-textPrimary">
                    {{ optional($lastAudit->started_at)->toDateTimeString() }} · {{ $lastAudit->status }} · {{ $lastAudit->pages_crawled }} pages
                </p>
                <p class="mt-1 text-xs text-textSecondary">
                    Issues: E {{ data_get($lastAudit, 'overview_issue_counts.error', data_get($lastAudit->issue_counts, 'error', 0)) }}, W {{ data_get($lastAudit, 'overview_issue_counts.warning', data_get($lastAudit->issue_counts, 'warning', 0)) }}, I {{ data_get($lastAudit, 'overview_issue_counts.info', data_get($lastAudit->issue_counts, 'info', 0)) }}
                </p>
                @if ($lastAudit->error_message)
                    <p class="mt-2 text-xs text-amber-700">{{ $lastAudit->error_message }}</p>
                @endif
            @else
                <p class="mt-1 text-sm text-textSecondary">No runs yet.</p>
            @endif
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-6">
            <form method="POST" action="{{ route('app.sites.seo-audits.run', $site) }}" class="flex flex-wrap items-end gap-4">
            @csrf
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Max pages this run</label>
                <input type="number" name="max_pages" min="1" max="500" value="50" class="w-28 rounded border border-border bg-background px-2 py-2 text-sm">
            </div>
            <button class="rounded border border-border px-3 py-2 text-sm">Run SEO audit</button>
            </form>
        </div>

        <div class="rounded-lg border border-border bg-surface p-6">
            <h2 class="text-sm font-semibold text-textPrimary">Recent audits</h2>
            <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm text-textPrimary">
                <thead>
                    <tr class="text-left text-xs text-textSecondary">
                        <th class="pb-2 font-medium">Started</th>
                        <th class="pb-2 font-medium">Status</th>
                        <th class="pb-2 font-medium">Pages</th>
                        <th class="pb-2 font-medium">Issues</th>
                        <th class="pb-2 font-medium">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($audits as $audit)
                        <tr class="border-t border-border/70">
                            <td class="py-2">{{ optional($audit->started_at)->toDateTimeString() }}</td>
                            <td class="py-2">
                                {{ $audit->status }}
                                @if ($audit->error_message)
                                    <p class="mt-1 text-xs text-amber-700">{{ $audit->error_message }}</p>
                                @endif
                            </td>
                            <td class="py-2">{{ $audit->pages_crawled }}</td>
                            <td class="py-2">E {{ data_get($audit, 'overview_issue_counts.error', data_get($audit->issue_counts, 'error', 0)) }} / W {{ data_get($audit, 'overview_issue_counts.warning', data_get($audit->issue_counts, 'warning', 0)) }} / I {{ data_get($audit, 'overview_issue_counts.info', data_get($audit->issue_counts, 'info', 0)) }}</td>
                            <td class="py-2"><a href="{{ route('app.sites.seo-audits.show', [$site, $audit]) }}" class="rounded border border-border px-2 py-1 text-xs">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-textSecondary">No audit runs yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
@endsection
