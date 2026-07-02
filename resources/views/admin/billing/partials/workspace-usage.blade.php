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

    <x-data-table label="Workspace quota usage" description="Monthly workspace quota usage by workspace, sites, articles, LLM queries, audit pages, and competitor slots." density="compact" class="border-0 shadow-none">
        <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Workspace</x-data-table.cell>
                <x-data-table.cell heading>Sites</x-data-table.cell>
                <x-data-table.cell heading>Articles generated</x-data-table.cell>
                <x-data-table.cell heading>LLM queries</x-data-table.cell>
                <x-data-table.cell heading>Audit pages</x-data-table.cell>
                <x-data-table.cell heading>Competitors</x-data-table.cell>
            </x-data-table.row>
        </x-data-table.header>
        <tbody class="divide-y divide-border">
        @forelse ($workspaceUsageRows as $row)
            <x-data-table.row>
                <x-data-table.cell label="Workspace">{{ $row['workspace_name'] }}</x-data-table.cell>
                <x-data-table.cell label="Sites">{{ $row['sites_count'] }}</x-data-table.cell>
                <x-data-table.cell label="Articles generated">
                    {{ $row['usage']['articles_generated'] }}
                </x-data-table.cell>
                <x-data-table.cell label="LLM queries">
                    {{ $row['usage']['llm_queries_run'] }}
                    @if ($row['limits']['llm_queries_run'] >= 0)
                        / {{ $row['limits']['llm_queries_run'] }}
                    @else
                        / ∞
                    @endif
                </x-data-table.cell>
                <x-data-table.cell label="Audit pages">
                    {{ $row['usage']['audit_pages_crawled'] }}
                    @if ($row['limits']['audit_pages_crawled'] >= 0)
                        / {{ $row['limits']['audit_pages_crawled'] }}
                    @else
                        / ∞
                    @endif
                </x-data-table.cell>
                <x-data-table.cell label="Competitors">
                    {{ $row['usage']['competitor_slots_used'] }}
                    @if ($row['limits']['competitor_slots_used'] >= 0)
                        / {{ $row['limits']['competitor_slots_used'] }}
                    @else
                        / ∞
                    @endif
                </x-data-table.cell>
            </x-data-table.row>
        @empty
            <x-data-table.empty colspan="6" title="No workspace usage found" />
        @endforelse
        </tbody>
    </x-data-table>
</div>
