<x-data-table label="Content Inventory" description="Observed website pages from analytics and sitemap discovery with fetch, extraction, eligibility and activation state." density="compact">
    <x-data-table.header>
        <x-data-table.row>
            <x-data-table.cell heading>Page</x-data-table.cell>
            <x-data-table.cell heading>Type</x-data-table.cell>
            <x-data-table.cell heading>Language</x-data-table.cell>
            <x-data-table.cell heading>Source</x-data-table.cell>
            <x-data-table.cell heading>HTTP</x-data-table.cell>
            <x-data-table.cell heading>Fetch</x-data-table.cell>
            <x-data-table.cell heading>Extraction</x-data-table.cell>
            <x-data-table.cell heading>Seen</x-data-table.cell>
            <x-data-table.cell heading>Fetched</x-data-table.cell>
            <x-data-table.cell heading>Changed</x-data-table.cell>
            <x-data-table.cell heading>Eligibility</x-data-table.cell>
            <x-data-table.cell heading>Content</x-data-table.cell>
            <x-data-table.cell heading>Actions</x-data-table.cell>
        </x-data-table.row>
    </x-data-table.header>
    <tbody>
        @forelse ($pages as $page)
            @php
                $snapshot = $page->latestSnapshot;
                $extraction = $page->latestContentExtraction;
                $eligibility = $inventoryEligibility[$page->id] ?? null;
                $linkedContent = $page->contentPageLinks->first()?->content;
                $excluded = $eligibility && (in_array('review_excluded', $eligibility->reasons, true) || in_array('excluded_path', $eligibility->reasons, true));
                $url = $page->canonical_url ?: ($page->final_url ?: $page->first_seen_url);
                $rowLinkableContents = $linkableContents
                    ->filter(fn ($content) => ! $page->client_site_id || ! $content->client_site_id || (string) $content->client_site_id === (string) $page->client_site_id)
                    ->values();
            @endphp
            <x-data-table.row>
                <x-data-table.cell label="Page">
                    <a href="{{ route('app.page-intelligence.monitored-pages.show', $page) }}" class="font-medium text-textPrimary hover:underline">{{ $page->title_current ?: 'Observed website page' }}</a>
                    <p class="mt-1 break-all text-xs text-textSecondary">{{ $url }}</p>
                    @if ($extraction?->meta_description)
                        <p class="mt-1 line-clamp-2 text-xs text-textSecondary">{{ $extraction->meta_description }}</p>
                    @endif
                </x-data-table.cell>
                <x-data-table.cell label="Type">{{ $page->page_type ? str($page->page_type)->headline() : '-' }}</x-data-table.cell>
                <x-data-table.cell label="Language">{{ $extraction?->language ?: ($page->language_current ?: '-') }}</x-data-table.cell>
                <x-data-table.cell label="Source">
                    <p>{{ str($page->source_type)->headline() }}</p>
                    <p class="text-xs text-textSecondary">{{ $page->domain }}</p>
                </x-data-table.cell>
                <x-data-table.cell label="HTTP">{{ $snapshot?->http_status ?: ($snapshot?->error_code ?: '-') }}</x-data-table.cell>
                <x-data-table.cell label="Fetch"><x-data-table.badge :tone="$page->crawl_status === 'failed' ? 'danger' : ($page->last_fetched_at ? 'success' : 'neutral')" :label="$page->last_fetched_at ? str($page->crawl_status)->headline() : 'Not fetched'" /></x-data-table.cell>
                <x-data-table.cell label="Extraction"><x-data-table.badge :tone="$extraction ? 'success' : 'neutral'" :label="$extraction ? 'Extracted' : 'Missing'" /></x-data-table.cell>
                <x-data-table.cell label="Seen">{{ $page->last_seen_at?->diffForHumans() ?: $page->first_seen_at?->diffForHumans() ?: '-' }}</x-data-table.cell>
                <x-data-table.cell label="Fetched">{{ $page->last_fetched_at?->diffForHumans() ?: '-' }}</x-data-table.cell>
                <x-data-table.cell label="Changed">{{ $page->last_changed_at?->diffForHumans() ?: '-' }}</x-data-table.cell>
                <x-data-table.cell label="Eligibility">
                    <x-data-table.badge :tone="$excluded ? 'danger' : ($eligibility?->eligible ? 'success' : 'warning')" :label="$excluded ? 'Excluded' : ($eligibility?->eligible ? 'Eligible' : 'Ineligible')" />
                    @if ($eligibility && ! $eligibility->eligible && ! $excluded)
                        <p class="mt-1 text-xs text-textSecondary">{{ collect($eligibility->reasons)->map(fn ($reason) => str($reason)->headline())->implode(', ') }}</p>
                    @endif
                </x-data-table.cell>
                <x-data-table.cell label="Content">
                    @if ($linkedContent)
                        <a href="{{ route('app.content.show', $linkedContent) }}" class="font-medium text-textPrimary hover:underline">Linked</a>
                    @else
                        <span class="text-textSecondary">Unlinked</span>
                    @endif
                </x-data-table.cell>
                <x-data-table.cell label="Actions">
                    <x-data-table.actions align="start">
                        <a href="{{ route('app.page-intelligence.monitored-pages.show', $page) }}" class="rounded border border-border px-2 py-1 text-xs">Detail</a>
                        @if ($url)
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="rounded border border-border px-2 py-1 text-xs">Open</a>
                        @endif
                        @can('update', $page)
                            <form method="POST" action="{{ route('app.page-intelligence.content-inventory.refresh', $page) }}">
                                @csrf
                                <button class="rounded border border-border px-2 py-1 text-xs">Refresh</button>
                            </form>
                            @if ($excluded)
                                <form method="POST" action="{{ route('app.page-intelligence.content-inventory.include', $page) }}">
                                    @csrf
                                    <button class="rounded border border-border px-2 py-1 text-xs">Include</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('app.page-intelligence.content-inventory.exclude', $page) }}">
                                    @csrf
                                    <button class="rounded border border-border px-2 py-1 text-xs">Exclude</button>
                                </form>
                            @endif
                            @if (! $linkedContent && $eligibility?->eligible)
                                @if ($rowLinkableContents->isNotEmpty())
                                    <form method="POST" action="{{ route('app.page-intelligence.content-inventory.link-content', $page) }}" class="flex max-w-64 items-center gap-1">
                                        @csrf
                                        <select name="content_id" class="min-w-0 rounded border border-border bg-background px-2 py-1 text-xs">
                                            @foreach ($rowLinkableContents as $content)
                                                @php($contentLabel = $content->title ?: ($content->published_url ?: ($content->normalized_url ?: 'Untitled content')))
                                                <option value="{{ $content->id }}">{{ str($contentLabel)->limit(48) }}</option>
                                            @endforeach
                                        </select>
                                        <button class="rounded border border-border px-2 py-1 text-xs">Link</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('app.page-intelligence.content-inventory.activate', $page) }}">
                                    @csrf
                                    <button class="rounded border border-border px-2 py-1 text-xs">Activate</button>
                                </form>
                            @endif
                        @endcan
                    </x-data-table.actions>
                </x-data-table.cell>
            </x-data-table.row>
        @empty
            <x-data-table.empty colspan="13" title="No inventory pages found" description="Run analytics-observed or sitemap discovery to populate owned website pages." />
        @endforelse
    </tbody>
    <x-slot:pagination>{{ $pages->links() }}</x-slot:pagination>
</x-data-table>
