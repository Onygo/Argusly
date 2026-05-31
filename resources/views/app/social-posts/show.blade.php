<x-app.layout title="Social post | Argusly">
    <div class="mx-auto max-w-5xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
            <div>
                <p class="eyebrow">Social publishing</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink">Social post detail</h1>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-ui.badge>{{ str($post->provider)->headline() }}</x-ui.badge>
                    <x-ui.badge :variant="$post->status === 'published' ? 'success' : ($post->status === 'failed' ? 'dark' : 'default')">{{ str($post->status)->headline() }}</x-ui.badge>
                    <span class="text-sm text-muted">{{ strtoupper($post->language) }} · {{ $post->locale }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.social-posts.index') }}" variant="secondary">Back</x-ui.button>
                @can('approve', $post)
                    <form method="POST" action="{{ route('app.social-posts.approve', $post) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">Approve placeholder</x-ui.button>
                    </form>
                @endcan
                @can('publish', $post)
                    @if (! in_array($post->status, ['published', 'queued', 'publishing'], true))
                        <form method="POST" action="{{ route('app.social-posts.publish', $post) }}">
                            @csrf
                            <x-ui.button type="submit">Publish fake · {{ config('credits.costs.social_publish') }}</x-ui.button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</div>
        @endif

        <div class="mt-8 grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <x-ui.card class="p-6">
                <h2 class="text-sm font-semibold text-ink">Post text</h2>
                <div class="mt-4 whitespace-pre-line text-sm leading-7 text-muted">{{ $post->post_text }}</div>
            </x-ui.card>

            <div class="space-y-5">
                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-ink">Publishing details</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-muted">Profile</dt><dd class="font-medium text-ink">{{ $post->socialProfile?->display_name }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-muted">Brand</dt><dd class="font-medium text-ink">{{ $post->brand?->name }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-muted">Market</dt><dd class="font-medium text-ink">{{ $post->market ?? 'Not set' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-muted">Scheduled</dt><dd class="font-medium text-ink">{{ $post->scheduled_at?->toFormattedDateString() ?? 'Not scheduled' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-muted">Published</dt><dd class="font-medium text-ink">{{ $post->published_at?->toFormattedDateString() ?? 'Not published' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-muted">Created by</dt><dd class="font-medium text-ink">{{ $post->creator?->name ?? 'System' }}</dd></div>
                    </dl>
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-ink">Schedule placeholder</h2>
                    @can('schedule', $post)
                        <form method="POST" action="{{ route('app.social-posts.schedule', $post) }}" class="mt-4 flex gap-2">
                            @csrf
                            <input type="datetime-local" name="scheduled_at" class="w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            <x-ui.button type="submit" variant="secondary">Save</x-ui.button>
                        </form>
                    @else
                        <p class="mt-2 text-sm text-muted">No schedule access for this profile.</p>
                    @endcan
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-ink">External result</h2>
                    <div class="mt-4 space-y-2 text-sm text-muted">
                        <p>{{ $post->external_id ?? 'No external id yet' }}</p>
                        @if ($post->external_url)
                            <a href="{{ $post->external_url }}" class="font-medium text-blue" target="_blank" rel="noreferrer">{{ $post->external_url }}</a>
                        @else
                            <p>No external URL yet</p>
                        @endif
                        @if ($post->error_message)
                            <p class="font-medium text-red-600">{{ $post->error_message }}</p>
                        @endif
                    </div>
                </x-ui.card>
            </div>
        </div>
    </div>
</x-app.layout>
