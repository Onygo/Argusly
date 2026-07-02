<div class="space-y-6">
    <x-llm-tracking.analysis-card
        title="Source breakdown"
        description="See which domains shaped the answer, how diverse the evidence is, and whether owned sources are part of the citation footprint."
        icon="globe"
    >
        <div class="rounded-lg border border-border bg-background px-4 py-3">
            <p class="text-sm text-textSecondary">{{ data_get($detail, 'sources.summary', 'No source data yet.') }}</p>
        </div>

        <x-data-table label="Source citations" description="Citation sources grouped by domain with source type, role, classification, branding, and answer position." density="compact" class="mt-4 border-0 shadow-none" table-class="min-w-full text-sm">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Domain</x-data-table.cell>
                    <x-data-table.cell heading>Type</x-data-table.cell>
                    <x-data-table.cell heading>Role</x-data-table.cell>
                    <x-data-table.cell heading>Class</x-data-table.cell>
                    <x-data-table.cell heading>Branding</x-data-table.cell>
                    <x-data-table.cell heading>Position</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody>
                @forelse ((array) data_get($detail, 'sources.rows', []) as $row)
                    <x-data-table.row>
                        <x-data-table.cell label="Domain">
                            <div class="font-medium text-textPrimary">{{ $row['domain'] ?? '' }}</div>
                            @if (($row['url'] ?? '') !== '')
                                <div class="mt-1 text-xs text-textMuted">{{ $row['url'] }}</div>
                            @endif
                        </x-data-table.cell>
                        <x-data-table.cell label="Type" class="text-textSecondary">{{ $row['type'] ?? '-' }}</x-data-table.cell>
                        <x-data-table.cell label="Role" class="text-textSecondary">{{ $row['role'] ?? '-' }}</x-data-table.cell>
                        <x-data-table.cell label="Class">
                            <x-llm-tracking.status-badge :label="$row['classification'] ?? '-'" :tone="$row['tone'] ?? 'slate'" />
                        </x-data-table.cell>
                        <x-data-table.cell label="Branding" class="text-textSecondary">{{ $row['branded'] ?? '-' }}</x-data-table.cell>
                        <x-data-table.cell label="Position" class="text-textSecondary">{{ $row['position'] ?? '-' }}</x-data-table.cell>
                    </x-data-table.row>
                @empty
                    <x-data-table.empty colspan="6" title="No source rows yet" description="Once the answer contains citations or extracted URLs, they will appear here." />
                @endforelse
            </tbody>
        </x-data-table>
    </x-llm-tracking.analysis-card>

    <x-llm-tracking.analysis-card
        title="Citation and evidence"
        description="Expandable evidence rows keep the page scanable while still exposing the snippets and rationale behind each source."
        icon="files"
    >
        <div class="space-y-3">
            @forelse ((array) data_get($detail, 'sources.citations', []) as $citation)
                <details class="group rounded-lg border border-border bg-background">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-textPrimary">{{ $citation['domain'] ?? '' }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $citation['type'] ?? '' }}</p>
                        </div>
                        <i data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-textMuted transition group-open:rotate-180"></i>
                    </summary>
                    <div class="space-y-3 border-t border-border px-4 py-4">
                        @if (($citation['url'] ?? '') !== '')
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">Source</p>
                                <p class="mt-1 text-sm text-textSecondary">{{ $citation['url'] }}</p>
                            </div>
                        @endif
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">Excerpt preview</p>
                            <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $citation['excerpt'] ?? '' }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">Why it matters</p>
                            <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $citation['why_it_matters'] ?? '' }}</p>
                        </div>
                    </div>
                </details>
            @empty
                <div class="rounded-lg border border-dashed border-border bg-background px-4 py-10 text-sm text-textMuted">
                    No citation evidence available yet.
                </div>
            @endforelse
        </div>
    </x-llm-tracking.analysis-card>
</div>
