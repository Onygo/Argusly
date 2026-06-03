<x-marketing.layout title="Blog | Argusly">
    <section class="border-b border-line bg-white">
        <div class="container-page py-16 sm:py-24">
            <p class="eyebrow">Blog</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-semibold leading-tight tracking-tight text-ink sm:text-6xl">Notes on AI visibility, brand intelligence and agentic marketing.</h1>
            <p class="mt-6 max-w-2xl text-base leading-7 text-muted">Public articles are powered by published content assets, keeping the marketing site connected to the existing content foundation.</p>
        </div>
    </section>

    <section class="section-pad bg-panel">
        <div class="container-page">
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @forelse ($posts as $post)
                    <a href="{{ route('marketing.blog.show', $post->slug) }}" class="rounded-md border border-line bg-white p-6 transition hover:border-slate-300">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">{{ $post->published_at?->format('Y-m-d') ?? $post->created_at?->format('Y-m-d') }}</p>
                        <h2 class="mt-3 text-xl font-bold text-ink">{{ $post->title }}</h2>
                        <p class="mt-3 line-clamp-3 text-sm leading-6 text-muted">{{ $post->excerpt ?: str($post->body)->stripTags()->limit(150) }}</p>
                    </a>
                @empty
                    <div class="rounded-md border border-line bg-white p-6">
                        <h2 class="text-lg font-bold text-ink">No public posts yet</h2>
                        <p class="mt-2 text-sm text-muted">Publish an article content asset to make it appear here.</p>
                    </div>
                @endforelse
            </div>

            <div class="mt-6">{{ $posts->links() }}</div>
        </div>
    </section>
</x-marketing.layout>
