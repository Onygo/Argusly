<x-app.layout :title="__('content.title').' | Argusly'">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Argusly Content Engine</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ __('content.content_assets') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Reusable assets for {{ $brand->name }} across articles, pages, social posts, newsletters and campaign surfaces.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge variant="blue">{{ $assets->total() }} assets</x-ui.badge>
                <x-ui.button href="{{ route('app.content.answer-blocks.index') }}" variant="secondary">{{ __('content.answer_blocks') }}</x-ui.button>
                @can('create', \App\Models\ContentAsset::class)
                    <x-ui.button href="{{ route('app.content.create') }}">{{ __('content.create_content') }}</x-ui.button>
                @endcan
            </div>
        </div>

        <x-ui.card class="mt-8 p-4">
            <form method="GET" action="{{ route('app.content.index') }}" class="grid gap-3 md:grid-cols-[1fr_1fr_1fr_auto]">
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
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('content.language') }}</span>
                    <select name="language" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ __('common.all_languages') }}</option>
                        @foreach ($contentLanguages as $language)
                            <option value="{{ $language->code }}" @selected(($filters['language'] ?? '') === $language->code)>{{ $language->name }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="flex items-end gap-2">
                    <x-ui.button type="submit">{{ __('common.filter') }}</x-ui.button>
                    <x-ui.button href="{{ route('app.content.index') }}" variant="light">{{ __('common.reset') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="mt-6 overflow-hidden rounded-md border border-line bg-white">
            <div class="hidden grid-cols-[1.4fr_0.7fr_0.7fr_0.7fr_0.7fr] gap-4 border-b border-line px-5 py-3 text-xs font-semibold uppercase tracking-[0.1em] text-muted md:grid">
                <span>Asset</span>
                <span>{{ __('common.type') }}</span>
                <span>{{ __('common.status') }}</span>
                <span>Lifecycle</span>
                <span>Updated</span>
            </div>
            @forelse ($assets as $asset)
                @php
                    $lifecycle = $asset->latestLifecycleScore->first();
                    $lifecycleVariant = match ($lifecycle?->status) {
                        'healthy' => 'success',
                        'critical', 'needs_refresh' => 'dark',
                        default => 'default',
                    };
                @endphp
                <a href="{{ route('app.content.show', $asset) }}" class="grid gap-3 border-b border-line px-5 py-4 transition last:border-b-0 hover:bg-panel md:grid-cols-[1.4fr_0.7fr_0.7fr_0.7fr_0.7fr] md:items-center">
                    <span>
                        <span class="block text-sm font-semibold text-ink">{{ $asset->title }}</span>
                        <span class="mt-1 block text-xs text-muted">{{ strtoupper($asset->language) }} · {{ $asset->locale }} · {{ $asset->source }}</span>
                    </span>
                    <span class="text-sm text-muted">{{ str($asset->type)->replace('_', ' ')->headline() }}</span>
                    <span>
                        <x-ui.badge :variant="$asset->status === 'published' ? 'success' : ($asset->status === 'failed' ? 'dark' : 'default')">
                            {{ str($asset->status)->headline() }}
                        </x-ui.badge>
                    </span>
                    <span>
                        <x-ui.badge :variant="$lifecycleVariant">
                            {{ $lifecycle ? str($lifecycle->status)->replace('_', ' ')->headline().' · '.$lifecycle->health_score : 'Unscored' }}
                        </x-ui.badge>
                    </span>
                    <span class="text-sm text-muted">{{ $asset->updated_at?->diffForHumans() }}</span>
                </a>
            @empty
                <x-dashboard.empty-state
                    title="No content assets yet"
                    message="Create a placeholder asset to start building the Content Engine foundation for this brand."
                />
            @endforelse
        </div>

        <div class="mt-6">
            {{ $assets->links() }}
        </div>
    </div>
</x-app.layout>
