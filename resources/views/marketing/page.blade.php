<x-marketing.layout :title="$content['title'].' | Argusly'">
    <section class="border-b border-line bg-white">
        <div class="container-page py-16 sm:py-24">
            <p class="eyebrow">{{ $content['eyebrow'] }}</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-semibold leading-tight tracking-tight text-ink sm:text-6xl">{{ $content['title'] }}</h1>
            <p class="mt-6 max-w-2xl text-base leading-7 text-muted">{{ $content['description'] }}</p>
        </div>
    </section>

    <section class="section-pad bg-panel">
        <div class="container-page grid gap-4 {{ in_array($page, ['privacy', 'terms'], true) ? 'max-w-4xl md:grid-cols-1' : 'md:grid-cols-3' }}">
            @foreach ($content['sections'] as $section)
                <article class="rounded-md border border-line bg-white p-6">
                    <h2 class="text-lg font-bold text-ink">{{ $section['title'] }}</h2>
                    <p class="mt-3 text-sm leading-6 text-muted">{{ $section['body'] }}</p>
                </article>
            @endforeach
        </div>
    </section>
</x-marketing.layout>
