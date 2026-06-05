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
                {{ __('public.roadmap.hero_title') }}
            </h1>
            <p class="mt-4 max-w-2xl text-pretty text-sm leading-6 text-textSecondary md:text-base">
                {{ __('public.roadmap.hero_text') }}
            </p>
        </div>
    </div>
</section>

{{-- Current Focus Areas --}}
<section class="bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mb-10 max-w-3xl">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-publicPrimary">{{ __('public.roadmap.focus_eyebrow') }}</p>
            <h2 class="text-2xl font-semibold text-textPrimary md:text-3xl">{{ __('public.roadmap.focus_title') }}</h2>
            <p class="mt-3 text-sm leading-6 text-textSecondary md:text-base">{{ __('public.roadmap.focus_text') }}</p>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach(__('public.roadmap.focus_areas') as $area)
                <div class="pl-public-card-compact p-5">
                    <x-public.icon :name="$area['icon']" size="sm" />
                    <h3 class="mt-4 text-sm font-semibold text-textPrimary">{{ $area['title'] }}</h3>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $area['text'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- How Prioritization Works --}}
<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-8 lg:grid-cols-2 lg:items-center">
            <div>
                <h2 class="text-2xl font-semibold text-textPrimary md:text-3xl">{{ __('public.roadmap.prioritization_title') }}</h2>
                <p class="mt-4 text-sm leading-6 text-textSecondary md:text-base">{{ __('public.roadmap.prioritization_text') }}</p>
            </div>
            <div class="pl-public-card p-6">
                <ul class="space-y-4">
                    @foreach(__('public.roadmap.prioritization_points') as $point)
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

{{-- Customer Feedback (dark) --}}
<section class="border-y border-publicPrimary/10 bg-publicPrimary">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-8 md:grid-cols-2 md:items-center">
            <div>
                <div class="mb-3 inline-flex items-center gap-2 text-xs font-semibold text-white/80">
                    <x-public.icon name="message-circle" size="xs" class="bg-white/10 text-white" />
                    <span>{{ __('public.roadmap.feedback_badge') }}</span>
                </div>
                <h2 class="text-2xl font-semibold text-white md:text-3xl">{{ __('public.roadmap.feedback_title') }}</h2>
                <p class="mt-3 text-sm leading-6 text-white/76 md:text-base">{{ __('public.roadmap.feedback_text') }}</p>
            </div>

            @php($feedbackPoints = __('public.roadmap.feedback_points'))
            @if(is_array($feedbackPoints) && count($feedbackPoints) > 0)
                <ul class="space-y-3 text-sm text-white/86">
                    @foreach($feedbackPoints as $point)
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
                {{ __('public.roadmap.cta_title') }}
            </h2>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-textSecondary md:text-base">
                {{ __('public.roadmap.cta_text') }}
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
