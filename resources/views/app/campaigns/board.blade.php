<x-app.layout title="{{ $campaign->name }} board | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Campaign planning board</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $campaign->name }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Move campaign work from idea to live using staged planning items for content, social, tasks and recommendations.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.campaigns.show', $campaign) }}" variant="secondary">Campaign detail</x-ui.button>
                <x-ui.button href="{{ route('app.campaigns') }}" variant="secondary">All campaigns</x-ui.button>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8">
            <x-dashboard.section title="Create board item" description="Add work manually or point the item at an existing content asset, social post, task or recommendation.">
                <form method="POST" action="{{ route('app.campaigns.board.items.store', $campaign) }}" class="grid gap-4 lg:grid-cols-4">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Title</span>
                        <input name="title" required class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Draft launch article">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Stage</span>
                        <select name="campaign_stage_id" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            @foreach ($stages as $stage)
                                <option value="{{ $stage->id }}">{{ $stage->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Status</span>
                        <select name="status" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            @foreach ($itemStatuses as $status)
                                <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Assignee</span>
                        <select name="assigned_to" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">Unassigned</option>
                            @foreach ($assignableUsers as $assignableUser)
                                <option value="{{ $assignableUser->id }}">{{ $assignableUser->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Due</span>
                        <input name="due_at" type="datetime-local" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Related type</span>
                        <select name="related_type" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">No related record</option>
                            @foreach ($relatedTypes as $relatedType)
                                <option value="{{ $relatedType }}">{{ str($relatedType)->headline() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Related ID</span>
                        <input name="related_id" type="number" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Optional ID">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Description</span>
                        <input name="description" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Short planning note">
                    </label>
                    <div class="lg:col-span-4">
                        <x-ui.button type="submit">Add item</x-ui.button>
                    </div>
                </form>
            </x-dashboard.section>
        </div>

        <div class="mt-6 overflow-x-auto pb-2">
            <div class="grid min-w-[1180px] grid-cols-7 gap-4">
                @foreach ($stages as $stage)
                    <section class="rounded-lg border border-line bg-white">
                        <div class="border-b border-line px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <h2 class="text-sm font-semibold text-ink">{{ $stage->name }}</h2>
                                <x-ui.badge>{{ $stage->items->count() }}</x-ui.badge>
                            </div>
                        </div>
                        <div class="space-y-3 p-3">
                            @forelse ($stage->items as $item)
                                <article class="rounded-lg border border-line bg-panel p-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="line-clamp-2 text-sm font-semibold text-ink">{{ $item->title }}</p>
                                            <p class="mt-1 line-clamp-2 text-xs leading-5 text-muted">{{ $item->description ?: $item->related?->title ?: $item->related?->name ?: 'No description' }}</p>
                                        </div>
                                        <x-ui.badge variant="{{ $item->status === 'completed' ? 'success' : ($item->status === 'blocked' ? 'blue' : 'default') }}">{{ str($item->status)->headline() }}</x-ui.badge>
                                    </div>
                                    <div class="mt-3 space-y-1 text-xs text-muted">
                                        <p>{{ $item->assignee?->name ?? 'Unassigned' }}</p>
                                        <p>{{ $item->due_at?->format('M j, Y H:i') ?? 'No due date' }}</p>
                                        @if ($item->related_type)
                                            <p>{{ class_basename($item->related_type) }} #{{ $item->related_id }}</p>
                                        @endif
                                    </div>
                                    <div class="mt-3 flex gap-2">
                                        <form method="POST" action="{{ route('app.campaigns.board.items.move', [$campaign, $item]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="left">
                                            <x-ui.button type="submit" variant="light">Left</x-ui.button>
                                        </form>
                                        <form method="POST" action="{{ route('app.campaigns.board.items.move', [$campaign, $item]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="right">
                                            <x-ui.button type="submit" variant="light">Right</x-ui.button>
                                        </form>
                                    </div>
                                </article>
                            @empty
                                <div class="rounded-lg border border-dashed border-line bg-panel p-4 text-sm text-muted">No items yet.</div>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    </div>
</x-app.layout>
