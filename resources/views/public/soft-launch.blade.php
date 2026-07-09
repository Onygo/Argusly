<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ \App\Support\SeoTitle::normalize($metaTitle ?? __('public.early_access.soft_launch_meta_title')) }}</title>
    <meta name="description" content="{{ $metaDescription ?? __('public.early_access.soft_launch_meta_description') }}" />
    @include('partials.brand-meta')
    @include('public.partials.argusly-tracking')
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/public.js'])
    <script defer src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="pl-marketing-v2 bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')

<main class="bg-background">
    {{-- Hero --}}
    <section class="pl-public-hero">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <span class="pl-public-hero-label">
                    {{ __('public.early_access.soft_launch_badge') }}
                </span>
                <h1 class="mt-4 pl-public-heading pl-public-heading-hero">
                    {{ __('public.early_access.soft_launch_title') }}
                </h1>
                <p class="mt-4 max-w-2xl text-pretty text-sm leading-6 text-textSecondary md:text-base">
                    {{ __('public.early_access.soft_launch_description') }}
                </p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('public.early-access.show', ['intent' => 'early_access']) }}" class="pl-public-primary-button">
                        {{ __('public.early_access.request_early_access') }}
                    </a>
                    <a href="{{ route('public.early-access.show', ['intent' => 'demo']) }}" class="pl-public-secondary-button">
                        {{ __('public.early_access.book_demo') }}
                    </a>
                    <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-full px-6 py-3 text-sm font-medium text-textSecondary transition-colors hover:bg-white">
                        {{ __('public.early_access.login') }}
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- Info Cards --}}
    <section class="bg-background">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-4 md:grid-cols-3">
                <article class="pl-public-card p-6">
                    <h2 class="pl-public-heading pl-public-heading-h3">{{ __('public.early_access.card_what_title') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-textSecondary">
                        {{ __('public.early_access.card_what_description') }}
                    </p>
                </article>
                <article class="pl-public-card p-6">
                    <h2 class="pl-public-heading pl-public-heading-h3">{{ __('public.early_access.card_who_title') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-textSecondary">
                        {{ __('public.early_access.card_who_description') }}
                    </p>
                </article>
                <article class="pl-public-card p-6">
                    <h2 class="pl-public-heading pl-public-heading-h3">{{ __('public.early_access.card_why_title') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-textSecondary">
                        {{ __('public.early_access.card_why_description') }}
                    </p>
                </article>
            </div>
        </div>
    </section>

    {{-- Agentic Marketing --}}
    <section class="border-y border-border bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
                <div>
                    <span class="inline-flex items-center rounded-md border border-publicPrimary/15 bg-[#f8fafc] px-3 py-1 text-xs font-medium text-publicPrimary">
                        {{ __('public.landing.agentic_badge') }}
                    </span>
                    <h2 class="mt-4 pl-public-heading pl-public-heading-h2">{{ __('public.landing.agentic_title') }}</h2>
                    <p class="mt-4 text-sm leading-7 text-textSecondary md:text-base">{{ __('public.landing.agentic_text') }}</p>
                    <div class="mt-7">
                        <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.agentic-marketing') }}" class="inline-flex items-center justify-center gap-2 rounded-full border border-publicPrimary/18 bg-white px-6 py-3 text-sm font-semibold text-publicPrimary transition-colors hover:bg-[#f8fafc]">
                            {{ __('public.landing.agentic_cta') }}
                            <x-public.icon name="arrow-right" size="xs" />
                        </a>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    @foreach (__('public.landing.agentic_visual_steps') as $step)
                        <div class="pl-public-card-compact pl-public-canvas p-5">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-publicPrimary text-xs font-semibold text-white">{{ $loop->iteration }}</span>
                            <h3 class="mt-4 text-sm font-semibold text-textPrimary">{{ $step['label'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $step['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="mx-auto max-w-3xl text-center">
                <h2 class="pl-public-heading pl-public-heading-h2">
                    {{ __('public.early_access.soft_launch_limited_title') }}
                </h2>
                <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-textSecondary md:text-base">
                    {{ __('public.early_access.soft_launch_limited_description') }}
                </p>
                <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                    <a href="{{ route('public.early-access.show', ['intent' => 'early_access']) }}" class="pl-public-primary-button">
                        {{ __('public.early_access.request_early_access') }}
                    </a>
                    <a href="{{ route('public.early-access.show', ['intent' => 'demo']) }}" class="pl-public-secondary-button">
                        {{ __('public.early_access.book_demo') }}
                    </a>
                </div>
            </div>
        </div>
    </section>
</main>

@include('public.partials.footer')

</body>
</html>
