<x-app.layout :title="__('intelligence.title').' | Argusly'">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">{{ __('intelligence.eyebrow') }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ __('intelligence.title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ __('intelligence.description', ['account' => $account->name, 'brand' => $brand ? ' and '.$brand->name : '']) }}</p>
            </div>
            <x-ui.badge variant="blue">{{ $signals->total() }} {{ __('intelligence.signals') }}</x-ui.badge>
        </div>

        <x-ui.card class="mt-8 p-4">
            <form method="GET" action="{{ route('app.intelligence') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.status') }}</span>
                    <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ __('common.all_statuses') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->replace('_', ' ')->headline() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.type') }}</span>
                    <select name="type" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ __('common.all_types') }}</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ str($type)->replace('_', ' ')->headline() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.category') }}</span>
                    <select name="category" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ __('common.all_categories') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ str($category)->headline() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.priority') }}</span>
                    <select name="priority" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ __('common.all_priorities') }}</option>
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority }}" @selected(($filters['priority'] ?? '') === $priority)>{{ str($priority)->headline() }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="flex items-end gap-2">
                    <x-ui.button type="submit">{{ __('common.filter') }}</x-ui.button>
                    <x-ui.button href="{{ route('app.intelligence') }}" variant="light">{{ __('common.reset') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="mt-6 space-y-4">
            @forelse ($signals as $signal)
                <x-intelligence.signal-card :signal="$signal" />
            @empty
                <x-dashboard.empty-state
                    :title="__('intelligence.no_signals_found')"
                    :message="__('intelligence.no_signals_message')"
                />
            @endforelse
        </div>

        <div class="mt-6">
            {{ $signals->links() }}
        </div>
    </div>
</x-app.layout>
