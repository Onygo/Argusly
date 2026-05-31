<x-app.settings.layout title="Knowledge Graph" description="Semantic entities and relationships that describe the current brand.">
    @if (! $brand)
        <x-dashboard.empty-state title="No brand selected" message="Select a brand before managing its knowledge graph." />
    @else
        <div class="grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <div class="space-y-6">
                <x-dashboard.section title="Add entity" description="Capture people, companies, products, services, locations, technologies and topics that matter to this brand.">
                    <form method="POST" action="{{ route('settings.knowledge-graph.entities.store') }}" class="space-y-4">
                        @csrf
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</span>
                            <input name="name" value="{{ old('name') }}" required class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Type</span>
                            <select name="entity_type" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($entityTypes as $type)
                                    <option value="{{ $type }}" @selected(old('entity_type') === $type)>{{ $type }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Aliases</span>
                            <input name="aliases" value="{{ old('aliases') }}" placeholder="Comma-separated aliases" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Description</span>
                            <textarea name="description" rows="4" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('description') }}</textarea>
                        </label>
                        <x-ui.button type="submit">Add entity</x-ui.button>
                    </form>
                </x-dashboard.section>

                <x-dashboard.section title="Add relationship" description="Connect brand entities into a graph that future scoring jobs can reason over.">
                    @if ($graph['brandEntities']->count() < 2)
                        <x-dashboard.empty-state title="Add more entities" message="At least two entities are required before relationships can be created." />
                    @else
                        <form method="POST" action="{{ route('settings.knowledge-graph.relationships.store') }}" class="space-y-4">
                            @csrf
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Source</span>
                                <select name="source_entity_id" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($graph['brandEntities'] as $brandEntity)
                                        <option value="{{ $brandEntity->entity_id }}">{{ $brandEntity->entity->name }} · {{ $brandEntity->entity->entity_type }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Relationship</span>
                                <select name="relationship_type" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($relationshipTypes as $type)
                                        <option value="{{ $type }}">{{ str($type)->replace('_', ' ')->headline() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Target</span>
                                <select name="target_entity_id" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($graph['brandEntities'] as $brandEntity)
                                        <option value="{{ $brandEntity->entity_id }}">{{ $brandEntity->entity->name }} · {{ $brandEntity->entity->entity_type }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <x-ui.button type="submit">Add relationship</x-ui.button>
                        </form>
                    @endif
                </x-dashboard.section>
            </div>

            <div class="space-y-6">
                <x-dashboard.section title="Semantic coverage" description="Current entity coverage by type.">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($entityTypes as $type)
                            <x-dashboard.info-card :label="$type" :value="$graph['typeCounts'][$type] ?? 0" />
                        @endforeach
                    </div>
                    <div class="mt-5 grid gap-3 sm:grid-cols-3">
                        @foreach ($graph['futureUseCases'] as $useCase)
                            <div class="rounded-lg border border-line bg-panel p-4">
                                <p class="text-sm font-semibold text-ink">{{ $useCase['label'] }}</p>
                                <p class="mt-1 text-xs text-muted">{{ str($useCase['status'])->headline() }}</p>
                            </div>
                        @endforeach
                    </div>
                </x-dashboard.section>

                <x-dashboard.section title="Brand entities">
                    @if ($graph['brandEntities']->isEmpty())
                        <x-dashboard.empty-state title="No entities" message="Add entities to begin building semantic understanding for this brand." />
                    @else
                        <div class="grid gap-4 lg:grid-cols-2">
                            @foreach ($graph['brandEntities'] as $brandEntity)
                                <article class="rounded-lg border border-line bg-white p-5">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold text-ink">{{ $brandEntity->entity->name }}</h3>
                                        <x-ui.badge variant="blue">{{ $brandEntity->entity->entity_type }}</x-ui.badge>
                                    </div>
                                    <p class="mt-2 text-sm leading-6 text-muted">{{ $brandEntity->entity->description ?: 'No description yet.' }}</p>
                                    @if (filled($brandEntity->entity->aliases))
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @foreach ($brandEntity->entity->aliases as $alias)
                                                <span class="rounded-full border border-line bg-panel px-2.5 py-1 text-xs font-semibold text-muted">{{ $alias }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>

                <x-dashboard.section title="Relationships">
                    @if ($graph['relationships']->isEmpty())
                        <x-dashboard.empty-state title="No relationships" message="Relationships will define how brand entities connect to products, topics, locations and competitors." />
                    @else
                        <div class="space-y-3">
                            @foreach ($graph['relationships'] as $relationship)
                                <div class="rounded-lg border border-line bg-white p-4">
                                    <p class="text-sm font-semibold text-ink">
                                        {{ $relationship->sourceEntity->name }}
                                        <span class="text-muted">{{ str($relationship->relationship_type)->replace('_', ' ')->headline() }}</span>
                                        {{ $relationship->targetEntity->name }}
                                    </p>
                                    <p class="mt-1 text-xs text-muted">{{ $relationship->sourceEntity->entity_type }} to {{ $relationship->targetEntity->entity_type }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>
            </div>
        </div>
    @endif
</x-app.settings.layout>
