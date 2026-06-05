<x-app.layout title="Sources & Citations | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">AI Visibility</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Sources & Citations</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Review the domains, source types and citations that AI providers mention for {{ $brand->name }}.</p>
            </div>
            <x-ui.button href="{{ route('app.visibility') }}" variant="secondary">Back to visibility</x-ui.button>
        </div>

        <form method="GET" action="{{ route('app.visibility.citations') }}" class="mt-6 grid gap-3 rounded-md border border-line bg-white p-4 md:grid-cols-3 xl:grid-cols-7">
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Provider</span>
                <select name="provider" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <option value="">All providers</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider }}" @selected(($filters['provider'] ?? null) === $provider)>{{ $provider }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Language</span>
                <select name="language" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <option value="">All languages</option>
                    @foreach ($contentLanguages as $language)
                        <option value="{{ $language->code }}" @selected(($filters['language'] ?? null) === $language->code)>{{ $language->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Market</span>
                <input name="market" value="{{ $filters['market'] ?? '' }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm uppercase text-ink" placeholder="US">
            </label>
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Domain</span>
                <input name="domain" value="{{ $filters['domain'] ?? '' }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="example.com">
            </label>
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Search</span>
                <input name="q" value="{{ $filters['q'] ?? '' }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Title or URL">
            </label>
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">From</span>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
            </label>
            <div class="flex items-end gap-2">
                <x-ui.button type="submit">Filter</x-ui.button>
                <x-ui.button href="{{ route('app.visibility.citations') }}" variant="secondary">Reset</x-ui.button>
            </div>
        </form>

        <x-dashboard.section class="mt-6" title="Source overview">
            @if ($sources->isEmpty())
                <x-dashboard.empty-state title="No sources found" message="Sources will appear after AI visibility answers or citations mention domains." />
            @else
                <div class="overflow-hidden rounded-md border border-line bg-white">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-panel text-left text-xs font-semibold uppercase tracking-[0.1em] text-muted">
                            <tr>
                                <th class="px-4 py-3">Source domain</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3 text-right">Seen</th>
                                <th class="px-4 py-3">Last seen</th>
                                <th class="px-4 py-3">Prompts</th>
                                <th class="px-4 py-3">Providers</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($sources as $source)
                                <tr>
                                    <td class="px-4 py-3 font-semibold text-ink">{{ $source['domain'] }}</td>
                                    <td class="px-4 py-3 text-muted">{{ str($source['type'])->headline() }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-ink">{{ $source['seen_count'] }}</td>
                                    <td class="px-4 py-3 text-muted">{{ $source['last_seen_at'] ? \Illuminate\Support\Carbon::parse($source['last_seen_at'])->diffForHumans() : '-' }}</td>
                                    <td class="px-4 py-3 text-muted">
                                        {{ collect($source['prompts'])->take(2)->implode(' · ') ?: '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-muted">{{ collect($source['providers'])->implode(', ') ?: '-' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            @if ($source['is_owned'])
                                                <x-ui.badge variant="blue">Owned</x-ui.badge>
                                            @endif
                                            @if ($source['is_competitor'])
                                                <x-ui.badge>Competitor</x-ui.badge>
                                            @endif
                                            @if (! $source['is_owned'] && ! $source['is_competitor'])
                                                <x-ui.badge>{{ $source['confidence_score'] }} confidence</x-ui.badge>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-dashboard.section>

        <x-dashboard.section class="mt-6" title="Citations">
            @if ($citations->isEmpty())
                <x-dashboard.empty-state title="No citations found" message="Citations will appear after AI visibility prompt runs complete for the current workspace and brand." />
            @else
                <div class="space-y-3">
                    @foreach ($citations as $citation)
                        <div class="rounded-md border border-line bg-white p-4">
                            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-ui.badge variant="blue">{{ $citation->providerRun?->provider ?? 'Provider' }}</x-ui.badge>
                                        <x-ui.badge>{{ str($citation->citation_type ?: 'unknown_source')->headline() }}</x-ui.badge>
                                        @if ($citation->providerRun?->language)
                                            <x-ui.badge>{{ strtoupper($citation->providerRun->language) }}</x-ui.badge>
                                        @endif
                                        @if ($citation->providerRun?->market)
                                            <x-ui.badge>{{ strtoupper($citation->providerRun->market) }}</x-ui.badge>
                                        @endif
                                        @if ($citation->rank)
                                            <span class="text-xs font-semibold text-muted">Rank {{ $citation->rank }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-3 text-sm font-semibold text-ink">{{ $citation->source_title ?: $citation->title ?: $citation->source_domain ?: $citation->domain ?: $citation->url }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ $citation->source_domain ?: $citation->domain ?: parse_url($citation->url, PHP_URL_HOST) }}</p>
                                </div>
                                <div class="shrink-0 text-right text-xs text-muted">
                                    <p>{{ $citation->created_at?->format('M j, Y') }}</p>
                                    <p class="mt-1">{{ $citation->confidence_score ?? $citation->trust_score ?? '-' }} confidence</p>
                                </div>
                            </div>
                            @if ($citation->snippet)
                                <p class="mt-3 text-sm leading-6 text-muted">{{ $citation->snippet }}</p>
                            @endif
                            <p class="mt-3 break-all text-xs text-muted">{{ $citation->source_url ?: $citation->url }}</p>
                            @if ($citation->providerRun)
                                <p class="mt-3 border-t border-line pt-3 text-xs text-muted">{{ $citation->providerRun->query }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-5">
                    {{ $citations->links() }}
                </div>
            @endif
        </x-dashboard.section>
    </div>
</x-app.layout>
