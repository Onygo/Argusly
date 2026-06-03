<x-app.layout title="{{ $entity->name }} | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Entity detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $entity->name }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $entity->description ?: 'No description yet.' }}</p>
            </div>
            <x-ui.button href="{{ route('app.entities.index') }}" variant="secondary">All entities</x-ui.button>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <x-dashboard.info-card label="Type" value="{{ str($entity->entity_type)->headline() }}" />
            <x-dashboard.info-card label="Status" value="{{ str($entity->status)->headline() }}" />
            <x-dashboard.info-card label="Scope" value="{{ $entity->brand_id ? 'Brand' : ($entity->account_id ? 'Account' : 'Global') }}" />
            <x-dashboard.info-card label="Relationships" :value="$entity->outgoing_relationships_count + $entity->incoming_relationships_count" />
            <x-dashboard.info-card label="Mentions" :value="$entity->mentions_count" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Relationship visualization" description="Placeholder for the future entity graph renderer.">
                <div class="rounded-md border border-dashed border-line bg-panel p-8">
                    <div class="mx-auto flex max-w-xl items-center justify-center gap-4 text-center">
                        <div class="rounded-md border border-line bg-white px-4 py-3 text-sm font-semibold text-ink">{{ $entity->name }}</div>
                        <div class="h-px w-16 bg-line"></div>
                        <div class="rounded-md border border-line bg-white px-4 py-3 text-sm font-semibold text-muted">Related entities</div>
                    </div>
                    <p class="mt-5 text-center text-sm leading-6 text-muted">Relationships, strength and evidence-backed context will power the graph for visibility, citations and relationship intelligence.</p>
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Aliases">
                @if ($entity->aliasRecords->isEmpty())
                    <x-dashboard.empty-state title="No aliases" message="Aliases help connect spelling variants, abbreviations and platform-specific names." />
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach ($entity->aliasRecords as $alias)
                            <span class="rounded-md border border-line bg-panel px-3 py-2 text-xs font-semibold text-muted">{{ $alias->alias }}</span>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <x-dashboard.section title="Outgoing relationships">
                @forelse ($entity->outgoingRelationships as $relationship)
                    <a href="{{ route('app.entities.show', $relationship->targetEntity) }}" class="mb-3 block rounded-md border border-line bg-panel p-4 hover:bg-white">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-ink">{{ $relationship->targetEntity->name }}</p>
                                <p class="mt-1 text-xs text-muted">{{ str($relationship->relationship_type)->headline() }}</p>
                            </div>
                            <x-ui.badge>{{ $relationship->strength !== null ? $relationship->strength : 'n/a' }}</x-ui.badge>
                        </div>
                    </a>
                @empty
                    <x-dashboard.empty-state title="No outgoing relationships" message="Outgoing relationship edges will appear here." />
                @endforelse
            </x-dashboard.section>

            <x-dashboard.section title="Incoming relationships">
                @forelse ($entity->incomingRelationships as $relationship)
                    <a href="{{ route('app.entities.show', $relationship->sourceEntity) }}" class="mb-3 block rounded-md border border-line bg-panel p-4 hover:bg-white">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-ink">{{ $relationship->sourceEntity->name }}</p>
                                <p class="mt-1 text-xs text-muted">{{ str($relationship->relationship_type)->headline() }}</p>
                            </div>
                            <x-ui.badge>{{ $relationship->strength !== null ? $relationship->strength : 'n/a' }}</x-ui.badge>
                        </div>
                    </a>
                @empty
                    <x-dashboard.empty-state title="No incoming relationships" message="Incoming relationship edges will appear here." />
                @endforelse
            </x-dashboard.section>

            <x-dashboard.section title="Linked context">
                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Topics</p>
                        <div class="mt-2 space-y-2">
                            @forelse ($entity->topics as $topic)
                                <a href="{{ route('app.topics.show', $topic) }}" class="block rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-ink hover:bg-white">{{ $topic->name }}</a>
                            @empty
                                <p class="text-sm text-muted">No linked topics.</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Mentions</p>
                        <div class="mt-2 space-y-2">
                            @forelse ($entity->mentions as $mention)
                                <a href="{{ route('app.mentions.show', $mention) }}" class="block rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-ink hover:bg-white">{{ $mention->title ?: str($mention->content)->limit(50) }}</a>
                            @empty
                                <p class="text-sm text-muted">No linked mentions.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
