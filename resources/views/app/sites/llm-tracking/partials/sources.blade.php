<div class="space-y-6">
    <x-llm-tracking.analysis-card
        title="Source breakdown"
        description="See which domains shaped the answer, how diverse the evidence is, and whether owned sources are part of the citation footprint."
        icon="globe"
    >
        <div class="rounded-lg border border-border bg-background px-4 py-3">
            <p class="text-sm text-textSecondary">{{ data_get($detail, 'sources.summary', 'No source data yet.') }}</p>
        </div>

        @if (! empty(data_get($detail, 'sources.rows', [])))
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-[0.16em] text-textMuted">
                            <th class="pb-3 pr-4 font-medium">Domain</th>
                            <th class="pb-3 pr-4 font-medium">Type</th>
                            <th class="pb-3 pr-4 font-medium">Role</th>
                            <th class="pb-3 pr-4 font-medium">Class</th>
                            <th class="pb-3 pr-4 font-medium">Branding</th>
                            <th class="pb-3 font-medium">Position</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ((array) data_get($detail, 'sources.rows', []) as $row)
                            <tr class="border-b border-border/60 align-top">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-textPrimary">{{ $row['domain'] ?? '' }}</div>
                                    @if (($row['url'] ?? '') !== '')
                                        <div class="mt-1 text-xs text-textMuted">{{ $row['url'] }}</div>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['type'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['role'] ?? '-' }}</td>
                                <td class="py-3 pr-4">
                                    <x-llm-tracking.status-badge :label="$row['classification'] ?? '-'" :tone="$row['tone'] ?? 'slate'" />
                                </td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['branded'] ?? '-' }}</td>
                                <td class="py-3 text-textSecondary">{{ $row['position'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="mt-4 rounded-lg border border-dashed border-border bg-background px-4 py-10 text-sm text-textMuted">
                No source rows yet. Once the answer contains citations or extracted URLs, they will appear here.
            </div>
        @endif
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
