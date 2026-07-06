<x-data-table label="Monitored pages" description="Canonical monitored page assets with latest extraction, sentiment, PR value, SERP, GEO and open alerts." density="compact">
    <x-data-table.header>
        <x-data-table.row>
            <x-data-table.cell heading>Page</x-data-table.cell>
            <x-data-table.cell heading>Source</x-data-table.cell>
            <x-data-table.cell heading>Sentiment</x-data-table.cell>
            <x-data-table.cell heading>Intelligence</x-data-table.cell>
            <x-data-table.cell heading>PR</x-data-table.cell>
            <x-data-table.cell heading>SERP</x-data-table.cell>
            <x-data-table.cell heading>GEO</x-data-table.cell>
            <x-data-table.cell heading>Alerts</x-data-table.cell>
            <x-data-table.cell heading>Actions</x-data-table.cell>
        </x-data-table.row>
    </x-data-table.header>
    <tbody>
        @forelse ($pages as $page)
            @php
                $row = collect($pageRows)->firstWhere('id', $page->id) ?? [];
                $resourceKey = \App\Support\Interaction\ResourceType::MONITORED_PAGE.':'.$page->id;
                $descriptor = $drawerDescriptorsByKey[$resourceKey] ?? null;
                $sentiment = data_get($pageInsights, 'sentiments.'.$page->id);
                $intelligence = data_get($pageInsights, 'intelligenceScores.'.$page->id);
                $pr = data_get($pageInsights, 'prValues.'.$page->id);
                $serp = data_get($pageInsights, 'serp.'.$page->id);
                $geo = data_get($pageInsights, 'geo.'.$page->id);
                $openAlerts = (int) data_get($pageInsights, 'alerts.'.$page->id, 0);
            @endphp
            <x-data-table.row>
                <x-data-table.cell label="Page">
                    <div class="min-w-0">
                        <a href="{{ route('app.page-intelligence.monitored-pages.show', $page) }}" class="font-medium text-textPrimary hover:underline">{{ $row['title'] ?? $page->title_current ?? 'Monitored page' }}</a>
                        <p class="mt-1 break-all text-xs text-textSecondary">{{ $row['url'] ?? $page->canonical_url }}</p>
                        @if (! empty($row['summary']))
                            <p class="mt-1 line-clamp-2 text-xs text-textSecondary">{{ $row['summary'] }}</p>
                        @endif
                    </div>
                </x-data-table.cell>
                <x-data-table.cell label="Source">
                    <p class="text-sm text-textPrimary">{{ $row['source'] ?? $page->source_type }}</p>
                    <p class="text-xs text-textSecondary">{{ $page->domain }}</p>
                </x-data-table.cell>
                <x-data-table.cell label="Sentiment">
                    <x-data-table.badge :tone="$sentiment?->label === 'negative' ? 'danger' : ($sentiment?->label === 'positive' ? 'success' : 'neutral')" :label="$sentiment?->label ? str($sentiment->label)->headline() : 'None'" />
                </x-data-table.cell>
                <x-data-table.cell label="Intelligence">{{ $intelligence ? number_format((float) $intelligence->score, 1) : '-' }}</x-data-table.cell>
                <x-data-table.cell label="PR">{{ $pr ? number_format((float) $pr->score, 1) : '-' }}</x-data-table.cell>
                <x-data-table.cell label="SERP">{{ $serp ? number_format((float) $serp->visibility_score, 1) : '-' }}</x-data-table.cell>
                <x-data-table.cell label="GEO">{{ $geo ? number_format((float) $geo->geo_visibility_score, 1) : '-' }}</x-data-table.cell>
                <x-data-table.cell label="Alerts">{{ $openAlerts }}</x-data-table.cell>
                <x-data-table.cell label="Actions">
                    <x-data-table.actions align="start">
                        @if ($descriptor)
                            <a
                                href="{{ data_get($descriptor, 'metadata.dashboard_url', route('app.page-intelligence.index', array_merge(request()->query(), ['drawer' => $page->id]))) }}"
                                class="rounded border border-border px-2 py-1 text-xs"
                                @foreach (($descriptor['data_attributes'] ?? []) as $attribute => $value)
                                    {{ $attribute }}="{{ $value }}"
                                @endforeach
                            >Inspect</a>
                        @endif
                        @if ($page->canonical_url)
                            <a href="{{ $page->canonical_url }}" target="_blank" rel="noopener noreferrer" class="rounded border border-border px-2 py-1 text-xs">Open URL</a>
                        @endif
                    </x-data-table.actions>
                </x-data-table.cell>
            </x-data-table.row>
        @empty
            <x-data-table.empty colspan="9" title="No monitored pages yet" description="Submit URLs or install market pack sources to start monitoring." />
        @endforelse
    </tbody>
    <x-slot:pagination>{{ $pages->links() }}</x-slot:pagination>
</x-data-table>
