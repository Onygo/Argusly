<x-app.layout title="Mentions | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Mention intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Mention feed</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Review brand, account and source mentions by monitored stream or corpus.</p>
            </div>
            <x-ui.badge variant="blue">{{ $mentions->total() }} mentions</x-ui.badge>
        </div>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Feed filters" description="Filter mentions by source, sentiment, publication date and brand scope.">
                <form method="GET" action="{{ route('app.mentions') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Source</span>
                        <select name="source_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">All sources</option>
                            @foreach ($sources as $source)
                                <option value="{{ $source->id }}" @selected((string) ($filters['source_id'] ?? '') === (string) $source->id)>{{ $source->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Sentiment</span>
                        <select name="sentiment" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">All sentiments</option>
                            @foreach ($sentiments as $sentiment)
                                <option value="{{ $sentiment }}" @selected(($filters['sentiment'] ?? '') === $sentiment)>{{ str($sentiment)->headline() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">From</span>
                        <input name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">To</span>
                        <input name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Brand</span>
                        <select name="brand_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">Current context</option>
                            <option value="account" @selected(($filters['brand_id'] ?? '') === 'account')>Account-level</option>
                            @foreach ($brands as $filterBrand)
                                <option value="{{ $filterBrand->id }}" @selected((string) ($filters['brand_id'] ?? '') === (string) $filterBrand->id)>{{ $filterBrand->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="md:col-span-2 xl:col-span-5">
                        <x-ui.button type="submit" variant="secondary">Apply filters</x-ui.button>
                    </div>
                </form>
            </x-dashboard.section>

            <x-dashboard.section title="Sentiment overview" description="Distribution across the current mention context.">
                <div class="grid grid-cols-2 gap-3">
                    @foreach (['positive' => 'success', 'neutral' => 'default', 'negative' => 'default', 'mixed' => 'blue'] as $sentiment => $variant)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ str($sentiment)->headline() }}</p>
                                <x-ui.badge variant="{{ $variant }}">{{ $sentimentOverview[$sentiment] }}</x-ui.badge>
                            </div>
                            <p class="mt-3 text-2xl font-semibold text-ink">{{ $sentimentOverview['total'] ? round(($sentimentOverview[$sentiment] / $sentimentOverview['total']) * 100) : 0 }}%</p>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Mentions">
                @if ($mentions->isEmpty())
                    <x-dashboard.empty-state title="No mentions" message="Mention records will appear here when internal capture or future source ingestion creates them." />
                @else
                    <div class="space-y-3">
                        @foreach ($mentions as $mention)
                            <a href="{{ route('app.mentions.show', $mention) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                                <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $mention->title ?: str($mention->content)->limit(80) ?: 'Untitled mention' }}</p>
                                            <x-ui.badge variant="{{ $mention->sentiment === 'positive' ? 'success' : ($mention->sentiment === 'mixed' ? 'blue' : 'default') }}">{{ $mention->sentiment ? str($mention->sentiment)->headline() : 'Unknown' }}</x-ui.badge>
                                            @if ($mention->brand)
                                                <x-ui.badge>{{ $mention->brand->name }}</x-ui.badge>
                                            @else
                                                <x-ui.badge>Account</x-ui.badge>
                                            @endif
                                        </div>
                                        <p class="mt-2 line-clamp-2 text-sm leading-6 text-muted">{{ $mention->content ?: 'No mention content captured yet.' }}</p>
                                        <p class="mt-2 text-xs text-muted">{{ $mention->source?->name ?? 'No source' }}{{ $mention->author ? ' · '.$mention->author : '' }}</p>
                                    </div>
                                    <div class="shrink-0 text-left md:text-right">
                                        <p class="text-sm font-semibold text-ink">{{ $mention->impact_score ?? '-' }}</p>
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Impact</p>
                                        <p class="mt-3 text-xs text-muted">{{ $mention->published_at?->format('M j, Y') ?? 'No date' }}</p>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $mentions->links() }}</div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
