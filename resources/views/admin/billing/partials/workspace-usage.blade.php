<div class="mb-6 rounded-lg border border-border bg-surface p-4">
    <div class="mb-3 flex items-center justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-textPrimary">Workspace quota usage</h3>
            <p class="text-xs text-textSecondary">Reporting per month period (YYYYMM). Credit-billed article generation is usage-only.</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <input type="hidden" name="tab" value="{{ $activeTab }}">
            <label for="usage_period" class="text-xs text-textSecondary">Period</label>
            <input id="usage_period" type="text" name="usage_period" value="{{ $usagePeriod }}" class="w-24 rounded border border-border bg-background px-2 py-1.5 text-xs" placeholder="202602">
            <button class="rounded border border-border px-2 py-1.5 text-xs">Apply</button>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
            <tr class="text-left text-textSecondary">
                <th class="pb-2 font-medium">Workspace</th>
                <th class="pb-2 font-medium">Sites</th>
                <th class="pb-2 font-medium">Articles generated</th>
                <th class="pb-2 font-medium">LLM queries</th>
                <th class="pb-2 font-medium">Audit pages</th>
                <th class="pb-2 font-medium">Competitors</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-border">
            @forelse ($workspaceUsageRows as $row)
                <tr>
                    <td class="py-2">{{ $row['workspace_name'] }}</td>
                    <td class="py-2">{{ $row['sites_count'] }}</td>
                    <td class="py-2">
                        {{ $row['usage']['articles_generated'] }}
                    </td>
                    <td class="py-2">
                        {{ $row['usage']['llm_queries_run'] }}
                        @if ($row['limits']['llm_queries_run'] >= 0)
                            / {{ $row['limits']['llm_queries_run'] }}
                        @else
                            / ∞
                        @endif
                    </td>
                    <td class="py-2">
                        {{ $row['usage']['audit_pages_crawled'] }}
                        @if ($row['limits']['audit_pages_crawled'] >= 0)
                            / {{ $row['limits']['audit_pages_crawled'] }}
                        @else
                            / ∞
                        @endif
                    </td>
                    <td class="py-2">
                        {{ $row['usage']['competitor_slots_used'] }}
                        @if ($row['limits']['competitor_slots_used'] >= 0)
                            / {{ $row['limits']['competitor_slots_used'] }}
                        @else
                            / ∞
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="py-3 text-textSecondary">No workspace usage found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
