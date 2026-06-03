<x-app.layout title="Entities | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Entity intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Entities</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Search and filter the companies, people, products, services, topics and organizations that shape brand understanding.</p>
            </div>
        </div>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Entity library" description="Entities are scoped to global, account and current-brand context.">
                <form method="GET" action="{{ route('app.entities.index') }}" class="mb-5 grid gap-3 md:grid-cols-[1fr_160px_150px_150px_auto]">
                    <input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search entities or aliases" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <select name="entity_type" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All types</option>
                        @foreach ($entityTypes as $type)
                            <option value="{{ $type }}" @selected(($filters['entity_type'] ?? '') === $type)>{{ str($type)->headline() }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                    <select name="scope" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All scopes</option>
                        <option value="brand" @selected(($filters['scope'] ?? '') === 'brand')>Brand</option>
                        <option value="account" @selected(($filters['scope'] ?? '') === 'account')>Account</option>
                        <option value="global" @selected(($filters['scope'] ?? '') === 'global')>Global</option>
                    </select>
                    <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
                </form>

                @if ($entities->isEmpty())
                    <x-dashboard.empty-state title="No entities found" message="Entity records will appear as brand knowledge, mentions, topics, visibility checks and relationship data mature." />
                @else
                    <div class="space-y-3">
                        @foreach ($entities as $entity)
                            <a href="{{ route('app.entities.show', $entity) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $entity->name }}</p>
                                            <x-ui.badge variant="blue">{{ str($entity->entity_type)->headline() }}</x-ui.badge>
                                            <x-ui.badge>{{ str($entity->status)->headline() }}</x-ui.badge>
                                            <x-ui.badge variant="{{ $entity->brand_id ? 'blue' : 'default' }}">{{ $entity->brand_id ? 'Brand' : ($entity->account_id ? 'Account' : 'Global') }}</x-ui.badge>
                                        </div>
                                        <p class="mt-1 line-clamp-2 text-sm leading-6 text-muted">{{ $entity->description ?: 'No description yet.' }}</p>
                                        @if ($entity->aliasRecords->isNotEmpty())
                                            <p class="mt-2 truncate text-xs text-muted">Aliases: {{ $entity->aliasRecords->pluck('alias')->join(', ') }}</p>
                                        @endif
                                    </div>
                                    <div class="grid shrink-0 grid-cols-3 gap-2 text-center">
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $entity->outgoing_relationships_count + $entity->incoming_relationships_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Links</p>
                                        </div>
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $entity->mentions_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Mentions</p>
                                        </div>
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $entity->topics_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Topics</p>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $entities->links() }}</div>
                @endif
            </x-dashboard.section>

            <div class="space-y-6">
                <x-dashboard.section title="Why entities">
                    <div class="space-y-3">
                        @foreach (['AI Visibility', 'Prompt Monitoring', 'Citations', 'Knowledge Graph', 'Creator Intelligence', 'Relationship Intelligence'] as $lane)
                            <div class="flex items-center justify-between rounded-md border border-line bg-panel px-4 py-3">
                                <span class="text-sm font-semibold text-ink">{{ $lane }}</span>
                                <x-ui.badge>Prepared</x-ui.badge>
                            </div>
                        @endforeach
                    </div>
                </x-dashboard.section>

                <x-dashboard.section title="Relationship visualization">
                    <div class="rounded-md border border-dashed border-line bg-panel p-6 text-center">
                        <p class="text-sm font-semibold text-ink">Graph visualization placeholder</p>
                        <p class="mt-2 text-sm leading-6 text-muted">Entity nodes and relationship strength will render here when the visual graph layer is introduced.</p>
                    </div>
                </x-dashboard.section>
            </div>
        </div>
    </div>
</x-app.layout>
