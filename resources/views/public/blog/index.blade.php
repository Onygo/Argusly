<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @include('public.partials.seo-head', [
        'metaTitle' => $metaTitle,
        'metaDescription' => $metaDescription,
        'canonicalUrl' => $canonicalUrl,
        'hreflangUrls' => $hreflangUrls ?? [],
        'ogType' => $ogType ?? 'website',
        'robotsIndex' => $robotsIndex ?? true,
        'robotsFollow' => $robotsFollow ?? true,
    ])
    @include('partials.brand-meta')
    @include('public.partials.argusly-tracking', ['canonicalUrl' => $canonicalUrl ?? null])
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/public.js'])
    <script defer src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')

<main class="bg-background">
    <section class="pl-public-hero">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <span class="pl-public-pill">{{ __('public.blog.title') }}</span>
                <h1 class="mt-4 pl-public-heading pl-public-heading-hero">{{ __('public.blog.title') }}</h1>
                <p class="mt-4 max-w-2xl text-sm leading-7 text-textSecondary md:text-base">{{ __('public.blog.subtitle') }}</p>
            </div>
        </div>
    </section>

    <section class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 md:py-16">
            @if($connectorUnavailable ?? false)
                <div class="pl-public-card-compact p-6 text-sm text-textSecondary">
                    <p class="font-semibold text-textPrimary">{{ __('public.blog.unavailable_title') }}</p>
                    <p class="mt-2">{{ __('public.blog.unavailable_text') }}</p>
                </div>
            @else
                <div class="mb-8 pl-public-card-compact p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.blog.index') }}" class="inline-flex items-center rounded-md border px-3 py-1.5 text-xs font-medium {{ ($activeTag ?? '') === '' && ($activeCategory ?? '') === '' ? 'border-publicPrimary/20 bg-publicPrimary text-white' : 'border-border bg-[#f8fafc] text-textSecondary hover:text-textPrimary' }}">{{ __('public.blog.filter_all') }}</a>
                        @foreach(($categories ?? []) as $category)
                            @php($active = strtolower(trim((string) ($activeCategory ?? ''))) === strtolower(trim((string) $category)) )
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.blog.category', ['category' => $category]) }}" class="inline-flex items-center rounded-md border px-3 py-1.5 text-xs font-medium {{ $active ? 'border-publicPrimary/20 bg-publicPrimary text-white' : 'border-border bg-[#f8fafc] text-textSecondary hover:text-textPrimary' }}">{{ $category }}</a>
                        @endforeach
                        @foreach(($tags ?? []) as $tag)
                            @php($active = strtolower(trim((string) ($activeTag ?? ''))) === strtolower(trim((string) $tag)) )
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.blog.tag', ['tag' => $tag]) }}" class="inline-flex items-center rounded-md border px-3 py-1.5 text-xs font-medium {{ $active ? 'border-publicPrimary/20 bg-publicPrimary text-white' : 'border-border bg-[#f8fafc] text-textSecondary hover:text-textPrimary' }}">#{{ $tag }}</a>
                        @endforeach
                        <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.blog.rss') }}" class="ml-auto inline-flex items-center rounded-md border border-border bg-white px-3 py-1.5 text-xs font-medium text-textSecondary hover:text-textPrimary">RSS</a>
                    </div>
                </div>

                @if($posts->count() === 0)
                    <div class="pl-public-card-compact p-6 text-sm text-textSecondary">
                        <p>{{ __('public.blog.empty') }}</p>
                        @if(($blogSourceConfigured ?? true) === false && app()->environment(['local', 'development']))
                            <p class="mt-2 text-xs text-textSecondary">Configure <code>ARGUSLY_MARKETING_BLOG_SOURCE_MODE</code> and <code>ARGUSLY_MARKETING_BLOG_SOURCE_ID</code> to show posts.</p>
                        @endif
                    </div>
                @else
                    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        @foreach($posts as $post)
                            <article class="flex h-full flex-col pl-public-card-compact p-5">
                                @if(($post['featured_image'] ?? '') !== '')
                                    <a href="{{ $post['url'] ?? \App\Support\LocalizedMarketingUrl::route('public.blog.show', ['slug' => $post['slug']]) }}" class="mb-5 block overflow-hidden rounded-md border border-border bg-[#f8fafc]">
                                        <img
                                            src="{{ $post['featured_image'] }}"
                                            alt="{{ $post['title'] }}"
                                            class="aspect-[4/3] w-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                            @if(!empty($post['featured_image_width'])) width="{{ $post['featured_image_width'] }}" @endif
                                            @if(!empty($post['featured_image_height'])) height="{{ $post['featured_image_height'] }}" @endif
                                        >
                                    </a>
                                @endif
                                <div class="flex flex-wrap items-center gap-2 text-xs text-textSecondary">
                                    @if(($post['category'] ?? '') !== '')
                                        <span class="inline-flex items-center rounded-md border border-publicPrimary/15 bg-[#f8fafc] px-2.5 py-1 font-medium text-publicPrimary">{{ $post['category'] }}</span>
                                    @endif
                                    <span>{{ $post['published_date'] }}</span>
                                    @if(($post['reading_time'] ?? 0) > 0)
                                        <span>· {{ $post['reading_time'] }} {{ __('public.blog.min_read') }}</span>
                                    @endif
                                </div>
                                <h2 class="mt-4 pl-public-heading pl-public-heading-h3">
                                    <a href="{{ $post['url'] ?? \App\Support\LocalizedMarketingUrl::route('public.blog.show', ['slug' => $post['slug']]) }}" class="hover:underline">{{ $post['title'] }}</a>
                                </h2>
                                <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $post['excerpt'] }}</p>
                                @if(!empty($post['tags']))
                                    <div class="mt-4 flex flex-wrap gap-1.5 text-xs text-textSecondary">
                                        @foreach($post['tags'] as $tag)
                                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.blog.tag', ['tag' => $tag]) }}" class="rounded-md border border-border bg-[#f8fafc] px-2.5 py-1 hover:text-textPrimary">#{{ $tag }}</a>
                                        @endforeach
                                    </div>
                                @endif
                                <a href="{{ $post['url'] ?? \App\Support\LocalizedMarketingUrl::route('public.blog.show', ['slug' => $post['slug']]) }}" class="mt-6 inline-flex text-sm font-semibold text-publicPrimary hover:text-publicPrimaryHover">{{ __('public.blog.read_more') }}</a>
                            </article>
                        @endforeach
                    </div>

                    <div class="mt-8">
                        {{ $posts->onEachSide(1)->links() }}
                    </div>
                @endif
            @endif
        </div>
    </section>
</main>

@include('public.partials.footer')

</body>
</html>
