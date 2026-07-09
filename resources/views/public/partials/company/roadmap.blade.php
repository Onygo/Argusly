@php
    use App\Support\EarlyAccess;
    use App\Support\LocalizedMarketingUrl;

    $isEarlyAccess = EarlyAccess::enabled();
    $ctaHref = $isEarlyAccess
        ? LocalizedMarketingUrl::route('public.early-access.show', ['intent' => 'early_access'])
        : LocalizedMarketingUrl::route('pricing');
    $ctaLabel = $isEarlyAccess
        ? __('public.nav.early_access')
        : __('public.page.cta.primary');
@endphp

{{-- Hero --}}
<section class="pl-public-hero">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="max-w-3xl">
            <span class="pl-public-hero-label">
                {{ __('public.footer.roadmap') }}
            </span>
            <h1 class="mt-5 pl-public-heading pl-public-heading-hero">
                {{ __('public.roadmap.hero_title') }}
            </h1>
            <p class="mt-5 max-w-2xl text-pretty text-lg leading-8 text-textPrimary">
                {{ __('public.roadmap.hero_subtitle') }}
            </p>
            <p class="mt-4 max-w-2xl text-pretty text-sm leading-6 text-textSecondary md:text-base">
                {{ __('public.roadmap.hero_intro') }}
            </p>
        </div>
    </div>
</section>

{{-- Capability Roadmap --}}
<section class="bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mb-10 max-w-3xl">
            <p class="pl-public-eyebrow">{{ __('public.roadmap.capabilities_eyebrow') }}</p>
            <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ __('public.roadmap.capabilities_title') }}</h2>
            <p class="mt-3 text-sm leading-6 text-textSecondary md:text-base">{{ __('public.roadmap.capabilities_text') }}</p>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            @foreach(__('public.roadmap.capabilities') as $capability)
                <article class="flex h-full flex-col pl-public-card-compact p-5 md:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <x-public.icon :name="$capability['icon']" size="sm" class="flex-none" />
                        <span class="rounded-md border border-publicPrimary/15 bg-publicPrimary/5 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-publicPrimary">
                            {{ $capability['status'] }}
                        </span>
                    </div>
                    <h3 class="mt-5 pl-public-heading pl-public-heading-card">{{ $capability['title'] }}</h3>
                    <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $capability['text'] }}</p>
                    <ul class="mt-5 grid gap-2.5 border-t border-border/70 pt-5 text-sm text-textSecondary">
                        @foreach($capability['items'] as $item)
                            <li class="flex items-start gap-3">
                                <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- Roadmap Note --}}
<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-8 lg:grid-cols-2 lg:items-center">
            <div>
                <p class="pl-public-eyebrow">{{ __('public.roadmap.disclaimer_eyebrow') }}</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ __('public.roadmap.disclaimer_title') }}</h2>
                <p class="mt-4 text-sm leading-6 text-textSecondary md:text-base">{{ __('public.roadmap.disclaimer_text') }}</p>
            </div>
            <div class="pl-public-card p-6">
                <ul class="space-y-4">
                    @foreach(__('public.roadmap.principles') as $point)
                        <li class="flex items-start gap-3">
                            <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                            <span class="text-sm text-textSecondary">{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>

{{-- CTA --}}
<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="pl-public-cta-panel">
            <h2 class="text-balance pl-public-heading pl-public-heading-h2 text-white">
                {{ __('public.roadmap.cta_title') }}
            </h2>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-white/76 md:text-base">
                {{ __('public.roadmap.cta_text') }}
            </p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ $ctaHref }}" class="pl-public-cta-primary">
                    {{ $ctaLabel }}
                </a>
                <a href="{{ LocalizedMarketingUrl::route('public.contact') }}" class="pl-public-cta-secondary">
                    {{ __('public.cta.secondary') }}
                </a>
            </div>
        </div>
    </div>
</section>
