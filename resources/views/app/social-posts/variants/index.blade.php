<x-app.layout title="Social variants | Argusly">
    <div class="mx-auto max-w-6xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Social repurposing</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink">Select a variant</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Choose one generated variant to become the final social post draft.</p>
            </div>
            <x-ui.button href="{{ route('app.social-posts.show', $post) }}" variant="secondary">View post</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mt-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</div>
        @endif

        <div class="mt-8 grid gap-4 lg:grid-cols-3">
            @foreach ($variants as $variant)
                <x-ui.card class="p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-ink">{{ str($variant->variant_type)->replace('_', ' ')->headline() }}</h2>
                            <p class="mt-1 text-xs text-muted">{{ strtoupper($variant->language) }} · {{ str($variant->status)->headline() }}</p>
                        </div>
                        <x-ui.badge :variant="$variant->status === 'selected' ? 'success' : 'default'">{{ str($variant->status)->headline() }}</x-ui.badge>
                    </div>

                    <div class="mt-4 whitespace-pre-line text-sm leading-6 text-muted">{{ $variant->post_text }}</div>

                    <form method="POST" action="{{ route('app.social-posts.variants.select', [$post, $variant]) }}" class="mt-5">
                        @csrf
                        <x-ui.button type="submit" class="w-full">{{ $variant->status === 'selected' ? 'Selected' : 'Use this variant' }}</x-ui.button>
                    </form>
                </x-ui.card>
            @endforeach
        </div>
    </div>
</x-app.layout>
