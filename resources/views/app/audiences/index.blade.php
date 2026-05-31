<x-app.layout title="Audiences | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Marketing OS</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Audiences</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Organize addressable contacts and lightweight segments before activation channels are connected.</p>
            </div>
            <x-ui.button href="{{ route('app.marketing') }}" variant="secondary">Marketing OS</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Create audience" description="Use brand scope for campaign lists, or account scope for shared contact pools.">
                <form method="POST" action="{{ route('app.audiences.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Scope</span>
                            <select name="scope" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                <option value="brand">{{ $brand?->name ?? 'Current brand' }}</option>
                                <option value="account">Account-wide</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Status</span>
                            <select name="status" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Name</span>
                        <input name="name" required class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="AI visibility newsletter list">
                    </label>
                    <textarea name="description" rows="3" class="w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Audience description"></textarea>
                    <x-ui.button type="submit">Create audience</x-ui.button>
                </form>
            </x-dashboard.section>

            <x-dashboard.section title="Audience library" description="Tenant-safe lists with member and segment counts.">
                @if ($audiences->isEmpty())
                    <x-dashboard.empty-state title="No audiences" message="Create an audience to start grouping contacts." />
                @else
                    <div class="space-y-3">
                        @foreach ($audiences as $audience)
                            <a href="{{ route('app.audiences.show', $audience) }}" class="block rounded-lg border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-ink">{{ $audience->name }}</p>
                                    <x-ui.badge variant="{{ $audience->status === 'active' ? 'success' : 'default' }}">{{ str($audience->status)->headline() }}</x-ui.badge>
                                    <x-ui.badge>{{ $audience->brand?->name ?? 'Account-wide' }}</x-ui.badge>
                                </div>
                                <p class="mt-2 line-clamp-2 text-sm leading-6 text-muted">{{ $audience->description ?: 'No description yet.' }}</p>
                                <p class="mt-2 text-xs text-muted">{{ $audience->members_count }} members · {{ $audience->segments_count }} segments</p>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $audiences->links() }}</div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Create segment" description="Store rule definitions for later targeting; no activation runs from here yet.">
                <form method="POST" action="{{ route('app.segments.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Scope</span>
                            <select name="scope" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                <option value="brand">{{ $brand?->name ?? 'Current brand' }}</option>
                                <option value="account">Account-wide</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Status</span>
                            <select name="status" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Audience</span>
                        <select name="audience_id" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">No audience</option>
                            @foreach ($audienceOptions as $audience)
                                <option value="{{ $audience->id }}">{{ $audience->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Name</span>
                        <input name="name" required class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Engaged operators">
                    </label>
                    <textarea name="description" rows="2" class="w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Segment description"></textarea>
                    <textarea name="rules_json" rows="4" class="w-full rounded-lg border border-line bg-white px-3 py-2 font-mono text-xs text-ink" placeholder='{"field":"status","operator":"equals","value":"active"}'></textarea>
                    <x-ui.button type="submit">Create segment</x-ui.button>
                </form>
            </x-dashboard.section>

            <x-dashboard.section title="Segments" description="Stored targeting definitions for the current operating scope.">
                @if ($segments->isEmpty())
                    <x-dashboard.empty-state title="No segments" message="Create a segment to save reusable targeting rules." />
                @else
                    <div class="space-y-3">
                        @foreach ($segments as $segment)
                            <div class="rounded-lg border border-line bg-panel p-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-ink">{{ $segment->name }}</p>
                                    <x-ui.badge variant="{{ $segment->status === 'active' ? 'success' : 'default' }}">{{ str($segment->status)->headline() }}</x-ui.badge>
                                    <x-ui.badge>{{ $segment->audience?->name ?? 'No audience' }}</x-ui.badge>
                                </div>
                                <p class="mt-2 line-clamp-2 text-sm leading-6 text-muted">{{ $segment->description ?: 'No description yet.' }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
