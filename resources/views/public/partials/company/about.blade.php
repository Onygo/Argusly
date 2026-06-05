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
            <h1 class="text-balance text-4xl font-semibold tracking-tight text-textPrimary md:text-5xl">
                {{ __('public.about.hero_title') }}
            </h1>
            <p class="mt-4 max-w-2xl text-pretty text-sm leading-6 text-textSecondary md:text-base">
                {{ __('public.about.hero_text') }}
            </p>
        </div>
    </div>
</section>

{{-- Problem / Context --}}
<section class="bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-8 lg:grid-cols-2 lg:items-start">
            <div>
                <h2 class="text-2xl font-semibold text-textPrimary md:text-3xl">{{ __('public.about.problem_title') }}</h2>
                <p class="mt-4 text-sm leading-6 text-textSecondary md:text-base">{{ __('public.about.problem_text') }}</p>
            </div>
            <div class="pl-public-card-soft p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-publicPrimary">{{ __('public.about.struggle_eyebrow') }}</p>
                <ul class="mt-4 space-y-3">
                    @foreach(__('public.about.struggle_points') as $point)
                        <li class="flex items-start gap-3 text-sm text-textSecondary">
                            <x-public.icon name="x" size="xs" class="mt-0.5 flex-none bg-rose-100 text-rose-600" />
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>

{{-- Why Argusly --}}
<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mx-auto max-w-3xl text-center">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-publicPrimary">{{ __('public.about.why_eyebrow') }}</p>
            <h2 class="text-2xl font-semibold text-textPrimary md:text-3xl">{{ __('public.about.why_title') }}</h2>
            <p class="mt-4 text-sm leading-6 text-textSecondary md:text-base">{{ __('public.about.why_text') }}</p>
        </div>

        <div class="mt-10 grid gap-4 md:grid-cols-2">
            @foreach(__('public.about.approach_blocks') as $block)
                <div class="pl-public-card p-6">
                    <x-public.icon :name="$block['icon']" size="md" />
                    <h3 class="mt-4 text-lg font-semibold text-textPrimary">{{ $block['title'] }}</h3>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $block['text'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- Product Principles --}}
<section class="border-y border-border bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mx-auto mb-10 max-w-3xl text-center">
            <h2 class="text-2xl font-semibold text-textPrimary md:text-3xl">{{ __('public.about.principles_title') }}</h2>
            <p class="mt-2 text-sm text-textSecondary md:text-base">{{ __('public.about.principles_text') }}</p>
        </div>

        @php($principles = __('public.about.principles'))
        @if(is_array($principles) && count($principles) > 0)
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach($principles as $card)
                    <article class="pl-public-card-compact p-5">
                        @if(!empty($card['icon']))
                            <x-public.icon :name="$card['icon']" size="sm" />
                        @endif
                        <h3 class="{{ !empty($card['icon']) ? 'mt-4' : '' }} text-sm font-semibold text-textPrimary">{{ $card['title'] }}</h3>
                        @if(!empty($card['text']))
                            <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $card['text'] }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>

{{-- Trust / Governance (dark) --}}
<section class="border-y border-publicPrimary/10 bg-publicPrimary">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-8 md:grid-cols-2 md:items-center">
            <div>
                <div class="mb-3 inline-flex items-center gap-2 text-xs font-semibold text-white/80">
                    <x-public.icon name="shield" size="xs" class="bg-white/10 text-white" />
                    <span>{{ __('public.about.trust_badge') }}</span>
                </div>
                <h2 class="text-2xl font-semibold text-white md:text-3xl">{{ __('public.about.trust_title') }}</h2>
                <p class="mt-3 text-sm leading-6 text-white/76 md:text-base">{{ __('public.about.trust_text') }}</p>
            </div>

            @php($trustPoints = __('public.about.trust_points'))
            @if(is_array($trustPoints) && count($trustPoints) > 0)
                <ul class="space-y-3 text-sm text-white/86">
                    @foreach($trustPoints as $point)
                        <li class="flex gap-3 rounded-xl border border-white/10 bg-white/8 px-4 py-3">
                            <x-public.icon name="check" size="xs" class="mt-0.5 flex-none bg-white/10 text-white" />
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</section>

{{-- CTA --}}
<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mx-auto max-w-3xl text-center">
            <h2 class="text-balance text-2xl font-semibold tracking-tight text-textPrimary md:text-3xl">
                {{ __('public.about.cta_title') }}
            </h2>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-textSecondary md:text-base">
                {{ __('public.about.cta_text') }}
            </p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ $ctaHref }}" class="pl-public-primary-button">
                    {{ $ctaLabel }}
                </a>
                <a href="{{ LocalizedMarketingUrl::route('public.contact') }}" class="pl-public-secondary-button">
                    {{ __('public.cta.secondary') }}
                </a>
            </div>
        </div>
    </div>
</section>
