<x-app.layout title="{{ $mention->title ?: 'Mention' }} | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Mention detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $mention->title ?: 'Untitled mention' }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $mention->source?->name ?? 'No source connected' }}{{ $mention->published_at ? ' · '.$mention->published_at->format('M j, Y') : '' }}</p>
            </div>
            <x-ui.button href="{{ route('app.mentions') }}" variant="secondary">Back to feed</x-ui.button>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Sentiment" value="{{ $mention->sentiment ? str($mention->sentiment)->headline() : 'Unknown' }}" />
            <x-dashboard.info-card label="Impact score" :value="$mention->impact_score" empty="No score" />
            <x-dashboard.info-card label="Brand" :value="$mention->brand?->name" empty="Account-level" />
            <x-dashboard.info-card label="Author" :value="$mention->author" empty="Unknown" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Mention content">
                <div class="prose max-w-none text-sm leading-7 text-ink">
                    @if ($mention->content)
                        <p class="whitespace-pre-line">{{ $mention->content }}</p>
                    @else
                        <x-dashboard.empty-state title="No content" message="This mention has metadata but no captured body content yet." />
                    @endif
                </div>
                @if ($mention->url)
                    <div class="mt-5">
                        <x-ui.button href="{{ $mention->url }}" variant="secondary" target="_blank" rel="noreferrer">Open source URL</x-ui.button>
                    </div>
                @endif
            </x-dashboard.section>

            <div class="space-y-6">
                <x-evidence.list :items="$mention->evidenceItems" />

                <x-dashboard.section title="Entities">
                    @if ($mention->entities->isEmpty())
                        <x-dashboard.empty-state title="No entities" message="Entity extraction can attach people, brands, products and topics later." />
                    @else
                        <div class="space-y-3">
                            @foreach ($mention->entities as $entity)
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-line bg-panel p-4">
                                    <p class="text-sm font-semibold text-ink">{{ $entity->entity_name }}</p>
                                    <x-ui.badge>{{ str($entity->entity_type)->headline() }}</x-ui.badge>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>

                <x-dashboard.section title="Relationships">
                    @if ($mention->relationships->isEmpty() && $mention->topics->isEmpty())
                        <x-dashboard.empty-state title="No relationships" message="Mentions can be linked to topics, content, competitors, recommendations and agents as those workflows mature." />
                    @else
                        <div class="space-y-3">
                            @foreach ($mention->topics as $topic)
                                <a href="{{ route('app.topics.show', $topic) }}" class="flex items-center justify-between rounded-lg border border-line bg-panel p-4 hover:bg-white">
                                    <span class="text-sm font-semibold text-ink">{{ $topic->name }}</span>
                                    <x-ui.badge>Topic</x-ui.badge>
                                </a>
                            @endforeach
                            @foreach ($mention->relationships as $relationship)
                                <div class="rounded-lg border border-line bg-panel p-4">
                                    <p class="text-sm font-semibold text-ink">{{ class_basename($relationship->related_type) }} #{{ $relationship->related_id }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>
            </div>
        </div>
    </div>
</x-app.layout>
