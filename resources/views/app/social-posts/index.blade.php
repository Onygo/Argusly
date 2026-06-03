<x-app.layout title="Social posts | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Social publishing</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Social posts</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Publishing history and prepared posts for {{ $brand->name }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.badge variant="blue">{{ $posts->total() }} posts</x-ui.badge>
                <x-ui.button href="{{ route('app.social-posts.create') }}">Create social post</x-ui.button>
            </div>
        </div>

        <x-ui.card class="mt-8 p-4">
            <form method="GET" action="{{ route('app.social-posts.index') }}" class="grid gap-3 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Brand</span>
                    <select name="brand_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">Current brand</option>
                        @foreach ($brands as $optionBrand)
                            <option value="{{ $optionBrand->id }}" @selected((string) ($filters['brand_id'] ?? '') === (string) $optionBrand->id)>{{ $optionBrand->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Provider</span>
                    <select name="provider" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All providers</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider }}" @selected(($filters['provider'] ?? '') === $provider)>{{ str($provider)->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                    <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Language</span>
                    <select name="language" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All languages</option>
                        @foreach ($contentLanguages as $language)
                            <option value="{{ $language->code }}" @selected(($filters['language'] ?? '') === $language->code)>{{ $language->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <x-ui.button type="submit">Filter</x-ui.button>
                    <x-ui.button href="{{ route('app.social-posts.index') }}" variant="light">Reset</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="mt-6 overflow-hidden rounded-md border border-line bg-white">
            <div class="hidden grid-cols-[1.2fr_0.6fr_0.7fr_0.7fr_0.8fr] gap-4 border-b border-line px-5 py-3 text-xs font-semibold uppercase tracking-[0.1em] text-muted md:grid">
                <span>Post</span>
                <span>Provider</span>
                <span>Status</span>
                <span>Language</span>
                <span>Published</span>
            </div>
            @forelse ($posts as $post)
                <a href="{{ route('app.social-posts.show', $post) }}" class="grid gap-3 border-b border-line px-5 py-4 transition last:border-b-0 hover:bg-panel md:grid-cols-[1.2fr_0.6fr_0.7fr_0.7fr_0.8fr] md:items-center">
                    <span>
                        <span class="block line-clamp-2 text-sm font-semibold text-ink">{{ $post->post_text }}</span>
                        <span class="mt-1 block text-xs text-muted">{{ $post->socialProfile?->display_name }} · {{ $post->brand?->name }}</span>
                    </span>
                    <span class="text-sm text-muted">{{ str($post->provider)->headline() }}</span>
                    <span>
                        <x-ui.badge :variant="$post->status === 'published' ? 'success' : ($post->status === 'failed' ? 'dark' : 'default')">{{ str($post->status)->headline() }}</x-ui.badge>
                    </span>
                    <span class="text-sm text-muted">{{ strtoupper($post->language) }} · {{ $post->locale }}</span>
                    <span class="text-sm text-muted">{{ $post->published_at?->diffForHumans() ?? $post->scheduled_at?->toFormattedDateString() ?? 'Not published' }}</span>
                </a>
            @empty
                <x-dashboard.empty-state title="No social posts yet" message="Prepare social posts from content assets or draft them directly for connected social profiles." />
            @endforelse
        </div>

        <div class="mt-6">
            {{ $posts->links() }}
        </div>
    </div>
</x-app.layout>
