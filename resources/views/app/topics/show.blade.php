<x-app.layout title="{{ $topic->name }} | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Topic detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $topic->name }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $topic->description ?: 'No description yet.' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.topics.edit', $topic) }}" variant="secondary">Edit</x-ui.button>
                <x-ui.button href="{{ route('app.topics.index') }}" variant="secondary">All topics</x-ui.button>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-6 lg:grid-cols-4">
            <x-dashboard.info-card label="Status" value="{{ str($topic->status)->headline() }}" />
            <x-dashboard.info-card label="Scope" value="{{ $topic->brand_id ? 'Brand' : ($topic->account_id ? 'Account' : 'Global') }}" />
            <x-dashboard.info-card label="Clusters" :value="$topic->clusters->count()" />
            <x-dashboard.info-card label="Relationships" :value="$topic->childRelationships->count() + $topic->parentRelationships->count()" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Relationships" description="Map how this topic supports, competes with, contains or relates to other topics.">
                @if ($topic->childRelationships->isEmpty() && $topic->parentRelationships->isEmpty())
                    <x-dashboard.empty-state title="No relationships" message="Connect this topic to adjacent topics as the intelligence graph matures." />
                @else
                    <div class="space-y-3">
                        @foreach ($topic->childRelationships as $relationship)
                            <div class="flex items-center justify-between gap-4 rounded-lg border border-line bg-panel p-4">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $relationship->childTopic->name }}</p>
                                    <p class="mt-1 text-xs text-muted">Outgoing relationship</p>
                                </div>
                                <x-ui.badge>{{ str($relationship->relationship_type)->headline() }}</x-ui.badge>
                            </div>
                        @endforeach
                        @foreach ($topic->parentRelationships as $relationship)
                            <div class="flex items-center justify-between gap-4 rounded-lg border border-line bg-panel p-4">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $relationship->parentTopic->name }}</p>
                                    <p class="mt-1 text-xs text-muted">Incoming relationship</p>
                                </div>
                                <x-ui.badge>{{ str($relationship->relationship_type)->headline() }}</x-ui.badge>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>

            <div class="space-y-6">
                <x-dashboard.section title="Add relationship">
                    <form method="POST" action="{{ route('app.topics.relationships.store') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="parent_topic_id" value="{{ $topic->id }}">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Related topic</span>
                            <select name="child_topic_id" required class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($availableTopics as $availableTopic)
                                    <option value="{{ $availableTopic->id }}">{{ $availableTopic->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Type</span>
                            <select name="relationship_type" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($relationshipTypes as $type)
                                    <option value="{{ $type }}">{{ str($type)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <x-ui.button type="submit">Connect topic</x-ui.button>
                    </form>
                </x-dashboard.section>

                <x-dashboard.section title="Future links" description="Prepared relationship lanes for later modules.">
                    <div class="grid gap-2">
                        @foreach (['Content Assets', 'Visibility Checks', 'Competitors', 'Mentions', 'Recommendations', 'Agents'] as $lane)
                            <div class="flex items-center justify-between rounded-lg border border-line bg-panel px-4 py-3">
                                <span class="text-sm font-semibold text-ink">{{ $lane }}</span>
                                <x-ui.badge>Ready</x-ui.badge>
                            </div>
                        @endforeach
                    </div>
                </x-dashboard.section>

                <form method="POST" action="{{ route('app.topics.destroy', $topic) }}" onsubmit="return confirm('Delete this topic?')">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="secondary">Delete topic</x-ui.button>
                </form>
            </div>
        </div>
    </div>
</x-app.layout>
