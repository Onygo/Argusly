<x-app.layout title="{{ $cluster->name }} | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Topic cluster</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $cluster->name }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $cluster->description ?: 'No description yet.' }}</p>
            </div>
            <x-ui.button href="{{ route('app.topics.index') }}" variant="secondary">All topics</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Edit cluster" description="Clusters are brand-scoped groupings of related topics.">
                <form method="POST" action="{{ route('app.topics.clusters.update', $cluster) }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</span>
                        <input name="name" value="{{ old('name', $cluster->name) }}" required class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Description</span>
                        <textarea name="description" rows="4" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('description', $cluster->description) }}</textarea>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Topics</span>
                        <select name="topic_ids[]" multiple size="8" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            @foreach ($availableTopics as $topic)
                                <option value="{{ $topic->id }}" @selected($cluster->topics->contains('id', $topic->id))>{{ $topic->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex flex-wrap gap-3">
                        <x-ui.button type="submit">Save cluster</x-ui.button>
                    </div>
                </form>

                <form method="POST" action="{{ route('app.topics.clusters.destroy', $cluster) }}" class="mt-4" onsubmit="return confirm('Delete this cluster?')">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="secondary">Delete cluster</x-ui.button>
                </form>
            </x-dashboard.section>

            <x-dashboard.section title="Cluster topics" description="Topics in this cluster become a ready context package for future agents and monitoring jobs.">
                @if ($cluster->topics->isEmpty())
                    <x-dashboard.empty-state title="No topics assigned" message="Select topics on the left to fill this cluster." />
                @else
                    <div class="grid gap-3 lg:grid-cols-2">
                        @foreach ($cluster->topics as $topic)
                            <a href="{{ route('app.topics.show', $topic) }}" class="rounded-lg border border-line bg-panel p-4 hover:bg-white">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $topic->name }}</p>
                                        <p class="mt-1 line-clamp-2 text-xs leading-5 text-muted">{{ $topic->description ?: 'No description yet.' }}</p>
                                    </div>
                                    <x-ui.badge>{{ str($topic->status)->headline() }}</x-ui.badge>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
