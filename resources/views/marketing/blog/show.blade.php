<x-marketing.layout :title="$post->title.' | Argusly'">
    <article class="bg-white">
        <header class="border-b border-line">
            <div class="container-page py-16 sm:py-24">
                <a href="{{ route('marketing.blog') }}" class="text-sm font-semibold text-blue">Back to blog</a>
                <p class="mt-6 text-xs font-semibold uppercase tracking-[0.12em] text-muted">{{ $post->published_at?->format('Y-m-d') ?? $post->created_at?->format('Y-m-d') }}</p>
                <h1 class="mt-4 max-w-4xl text-4xl font-semibold leading-tight tracking-tight text-ink sm:text-6xl">{{ $post->title }}</h1>
                @if ($post->excerpt)
                    <p class="mt-6 max-w-2xl text-base leading-7 text-muted">{{ $post->excerpt }}</p>
                @endif
            </div>
        </header>

        <div class="container-page py-12">
            <div class="prose max-w-3xl text-ink">
                {!! nl2br(e($post->body ?: $post->excerpt ?: 'This article is published but does not have body content yet.')) !!}
            </div>
        </div>
    </article>
</x-marketing.layout>
