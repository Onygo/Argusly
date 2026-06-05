<x-app.layout title="Recommendations | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Recommendations</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Review, accept, dismiss or archive intelligence recommendations for the current workspace and brand context.</p>
            </div>
            <x-ui.badge variant="blue">{{ $recommendations->total() }} recommendations</x-ui.badge>
        </div>

        <div class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach (['open' => 'Open', 'reviewed' => 'Reviewed', 'accepted' => 'Accepted', 'completed' => 'Completed', 'archived' => 'Archived'] as $key => $label)
                <x-ui.card class="p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ $label }}</p>
                    <p class="mt-3 text-2xl font-semibold text-ink">{{ $stats[$key] ?? 0 }}</p>
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.card class="mt-6 p-4">
            <form method="GET" action="{{ route('app.intelligence.recommendations') }}" class="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                    <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Brand</span>
                    <select name="brand_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">Current scope</option>
                        <option value="account" @selected(($filters['brand_id'] ?? '') === 'account')>Account level</option>
                        @foreach ($brands as $filterBrand)
                            <option value="{{ $filterBrand->id }}" @selected(($filters['brand_id'] ?? '') === (string) $filterBrand->id)>{{ $filterBrand->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <x-ui.button type="submit">Filter</x-ui.button>
                    <x-ui.button href="{{ route('app.intelligence.recommendations') }}" variant="light">Reset</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="mt-6 space-y-4">
            @forelse ($recommendations as $recommendation)
                <x-recommendations.card :recommendation="$recommendation" />
            @empty
                <x-dashboard.empty-state title="No recommendations found" message="Recommendations will appear when intelligence signals identify useful next actions." />
            @endforelse
        </div>

        <div class="mt-6">
            {{ $recommendations->links() }}
        </div>
    </div>
</x-app.layout>
