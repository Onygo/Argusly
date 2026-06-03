<x-app.layout title="Topics | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Topic intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Topics</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Manage the topics that organize AI visibility, brand monitoring, competitive intelligence, content and agent workflows.</p>
            </div>
            <x-ui.button href="{{ route('app.topics.create') }}">New topic</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Topic library" description="Account topics and current-brand topics are shown together for this tenant context.">
                <form method="GET" action="{{ route('app.topics.index') }}" class="mb-5 grid gap-3 md:grid-cols-[1fr_160px_160px_auto]">
                    <input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search topics" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
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
                    </select>
                    <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
                </form>

                @if ($topics->isEmpty())
                    <x-dashboard.empty-state title="No topics yet" message="Create topics like AI Visibility, Agentic Marketing, Brand Monitoring or Customer Experience to start building the topic graph." />
                @else
                    <div class="space-y-3">
                        @foreach ($topics as $topic)
                            <a href="{{ route('app.topics.show', $topic) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $topic->name }}</p>
                                            <x-ui.badge variant="{{ $topic->brand_id ? 'blue' : 'default' }}">{{ $topic->brand_id ? 'Brand' : ($topic->account_id ? 'Account' : 'Global') }}</x-ui.badge>
                                            <x-ui.badge>{{ str($topic->status)->headline() }}</x-ui.badge>
                                        </div>
                                        <p class="mt-1 line-clamp-2 text-sm leading-6 text-muted">{{ $topic->description ?: 'No description yet.' }}</p>
                                    </div>
                                    <div class="grid shrink-0 grid-cols-3 gap-2 text-center">
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $topic->clusters_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Clusters</p>
                                        </div>
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $topic->child_relationships_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Out</p>
                                        </div>
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $topic->parent_relationships_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">In</p>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $topics->links() }}</div>
                @endif
            </x-dashboard.section>

            <div class="space-y-6">
                <x-dashboard.section title="Create cluster" description="Group related topics for the selected brand.">
                    @if (! $brand)
                        <x-dashboard.empty-state title="No brand selected" message="Select a brand before creating topic clusters." />
                    @else
                        <form method="POST" action="{{ route('app.topics.clusters.store') }}" class="space-y-4">
                            @csrf
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</span>
                                <input name="name" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Description</span>
                                <textarea name="description" rows="3" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink"></textarea>
                            </label>
                            <x-ui.button type="submit">Create cluster</x-ui.button>
                        </form>
                    @endif
                </x-dashboard.section>

                <x-dashboard.section title="Topic clusters">
                    @if ($clusters->isEmpty())
                        <x-dashboard.empty-state title="No clusters" message="Clusters will help group topics for brand dashboards and future agents." />
                    @else
                        <div class="space-y-3">
                            @foreach ($clusters as $cluster)
                                <a href="{{ route('app.topics.clusters.show', $cluster) }}" class="flex items-center justify-between gap-4 rounded-md border border-line bg-panel p-4 hover:bg-white">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $cluster->name }}</p>
                                        <p class="mt-1 text-xs text-muted">{{ $cluster->topics_count }} topics</p>
                                    </div>
                                    <span class="text-sm font-semibold text-blue">Open</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>
            </div>
        </div>
    </div>
</x-app.layout>
