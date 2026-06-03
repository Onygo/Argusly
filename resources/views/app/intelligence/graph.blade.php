<x-app.layout title="Knowledge Graph | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Knowledge Graph</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">A tenant-safe projection across brands, topics, entities, mentions, competitors, creators, narratives, campaigns, content and recommendations.</p>
            </div>
            <x-ui.badge variant="blue">{{ $summary['nodes'] ?? 0 }} nodes</x-ui.badge>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Graph Nodes" :value="$summary['nodes'] ?? 0" />
            <x-dashboard.info-card label="Graph Edges" :value="$summary['edges'] ?? 0" />
            <x-dashboard.info-card label="Node Types" :value="($summary['nodeCounts'] ?? collect())->count()" />
            <x-dashboard.info-card label="Graph Opportunities" :value="$opportunities->count()" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <x-dashboard.section title="Node Counts" description="Projected source records grouped by graph node type.">
                <div class="space-y-3">
                    @forelse (($summary['nodeCounts'] ?? collect()) as $type => $total)
                        <div class="flex items-center justify-between rounded-md border border-line bg-panel px-4 py-3 text-sm">
                            <span class="font-semibold text-ink">{{ str($type)->headline() }}</span>
                            <span class="text-muted">{{ $total }}</span>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No nodes" message="Run graph:rebuild or create intelligence records to populate the projection." />
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Edge Counts" description="Projected relationships grouped by graph relationship type.">
                <div class="space-y-3">
                    @forelse (($summary['edgeCounts'] ?? collect()) as $type => $total)
                        <div class="flex items-center justify-between rounded-md border border-line bg-panel px-4 py-3 text-sm">
                            <span class="font-semibold text-ink">{{ str($type)->replace('_', ' ')->headline() }}</span>
                            <span class="text-muted">{{ $total }}</span>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No edges" message="Relationships appear after projected domains share topics, mentions, campaigns or recommendations." />
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            @foreach ([
                'Top Connected Entities' => $summary['topConnected'] ?? collect(),
                'Most Mentioned Topics' => $summary['mostMentionedTopics'] ?? collect(),
                'Most Connected Competitors' => $summary['mostConnectedCompetitors'] ?? collect(),
                'Most Active Creators' => $summary['mostActiveCreators'] ?? collect(),
                'Most Referenced Narratives' => $summary['mostReferencedNarratives'] ?? collect(),
            ] as $title => $items)
                <x-dashboard.section :title="$title">
                    <div class="space-y-3">
                        @forelse ($items as $node)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <p class="truncate text-sm font-semibold text-ink">{{ $node->label }}</p>
                                <p class="mt-1 text-xs text-muted">{{ str($node->node_type)->headline() }} · {{ (int) ($node->connections_count ?? 0) }} connections</p>
                            </div>
                        @empty
                            <x-dashboard.empty-state title="No data" message="Connections will appear as the graph projection grows." />
                        @endforelse
                    </div>
                </x-dashboard.section>
            @endforeach
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <x-dashboard.section title="Recent Nodes" description="Latest projected graph nodes in the current tenant context.">
                <div class="overflow-hidden rounded-md border border-line">
                    <table class="w-full divide-y divide-line text-left text-sm">
                        <thead class="bg-panel text-xs uppercase tracking-[0.1em] text-muted">
                            <tr>
                                <th class="px-4 py-3">Label</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Source</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line bg-white">
                            @forelse ($nodes as $node)
                                <tr>
                                    <td class="px-4 py-3 font-semibold text-ink">{{ $node->label }}</td>
                                    <td class="px-4 py-3 text-muted">{{ str($node->node_type)->headline() }}</td>
                                    <td class="px-4 py-3 text-muted">{{ class_basename($node->source_type) }} #{{ $node->source_id }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-8 text-center text-sm text-muted">No graph nodes projected yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Recent Relationships" description="Latest projected graph edges in the current tenant context.">
                <div class="space-y-3">
                    @forelse ($edges as $edge)
                        <div class="rounded-md border border-line bg-panel p-4 text-sm">
                            <p class="font-semibold text-ink">{{ $edge->sourceNode?->label }} <span class="text-muted">{{ str($edge->relationship_type)->replace('_', ' ') }}</span> {{ $edge->targetNode?->label }}</p>
                            <p class="mt-1 text-xs text-muted">Confidence {{ $edge->confidence ?? 'n/a' }} · Strength {{ $edge->strength ?? 'n/a' }}</p>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No relationships" message="Edges will appear after related domains are projected." />
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
