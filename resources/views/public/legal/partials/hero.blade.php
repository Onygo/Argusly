<section class="pl-public-hero">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="max-w-3xl">
            <div class="mb-4 inline-flex items-center gap-2 rounded-full border border-publicPrimary/15 bg-white px-3 py-1 text-xs font-medium text-publicPrimary">
                <x-public.icon name="shield" size="xs" />
                <span>{{ __('public.legal.hub.badge') }}</span>
            </div>
            <h1 class="text-balance text-4xl font-semibold tracking-tight text-textPrimary md:text-5xl">{{ $title }}</h1>
            <p class="mt-4 max-w-2xl text-sm leading-6 text-textSecondary md:text-base">{{ $subtitle }}</p>
        </div>
    </div>
</section>
