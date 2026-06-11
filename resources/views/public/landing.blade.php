<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @include('public.partials.seo-head', [
        'metaTitle' => $metaTitle ?? __('public.landing.meta_title'),
        'metaDescription' => $metaDescription ?? __('public.landing.meta_description'),
        'canonicalUrl' => $canonicalUrl ?? null,
        'hreflangUrls' => $hreflangUrls ?? [],
        'ogType' => 'website',
    ])
    @include('partials.brand-meta')
    @include('public.partials.argusly-tracking', ['canonicalUrl' => $canonicalUrl ?? null])
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@php
    use App\Support\MarketingNavigation;
    use App\Support\EarlyAccess;

    $heroPrimaryCta = MarketingNavigation::homepagePrimaryCTA();
    $heroSecondaryCta = MarketingNavigation::homepageSecondaryCTA();
    $bottomCta = MarketingNavigation::landingBottomCTA();
    $isEarlyAccess = EarlyAccess::enabled();
@endphp

@include('public.partials.nav')

{{-- Hero --}}
<section class="pl-public-hero">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mx-auto max-w-3xl text-center">
            <div class="mx-auto mb-4 pl-public-hero-label">
                <x-public.icon name="sparkles" size="xs" />
                <span class="font-medium">{{ __('public.landing.hero_badge') }}</span>
            </div>

            <h1 class="pl-public-heading pl-public-heading-hero">
                {{ __('public.landing.hero_title') }}
            </h1>

            <p class="mx-auto mt-4 max-w-2xl text-pretty text-sm leading-6 text-textSecondary md:text-base">
                {{ __('public.landing.hero_text') }}
            </p>

            <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ MarketingNavigation::buildUrl($heroPrimaryCta) }}" class="pl-public-primary-button w-full sm:w-auto">{{ $heroPrimaryCta['label'] }}</a>
                @if (isset($heroSecondaryCta['href']))
                    <a href="{{ $heroSecondaryCta['href'] }}" class="pl-public-secondary-button w-full sm:w-auto">{{ $heroSecondaryCta['label'] }}</a>
                @else
                    <a href="{{ MarketingNavigation::buildUrl($heroSecondaryCta) }}" class="pl-public-secondary-button w-full sm:w-auto">{{ $heroSecondaryCta['label'] }}</a>
                @endif
            </div>
        </div>

    </div>
</section>

{{-- Problem / Pain Points --}}
<section class="bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mx-auto max-w-3xl text-center">
            <h2 class="pl-public-heading pl-public-heading-h2">{{ __('public.landing.problem_title') }}</h2>
            <p class="mt-2 text-sm text-textSecondary md:text-base">{{ __('public.landing.problem_text') }}</p>
        </div>

        <div class="mt-10 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach(trans('public.landing.pains') as $pain)
                <div class="pl-public-card-compact pl-public-canvas p-5">
                    <x-public.icon :name="$pain['icon']" size="sm" />
                    <h3 class="mt-4 text-sm font-semibold text-textPrimary">{{ $pain['title'] }}</h3>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $pain['text'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- How It Works --}}
<section id="how" class="border-y border-publicPrimary/10 bg-publicPrimary">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mx-auto max-w-3xl text-center">
            <h2 class="pl-public-heading pl-public-heading-h2 text-white">{{ __('public.landing.how_title') }}</h2>
            <p class="mt-2 text-sm text-white/76 md:text-base">{{ __('public.landing.how_text') }}</p>
        </div>

        <div class="mt-10 rounded-md border border-white/12 bg-white/8 px-5 py-7 md:px-8">
            <div class="grid items-center gap-6 md:grid-cols-4">
                @foreach(trans('public.landing.steps') as $index => $step)
                    <div class="pl-animate-step-{{ $index + 1 }} rounded-md border border-white/10 bg-white/95 px-4 py-5 text-center">
                        <x-public.icon :name="$step['icon']" size="md" class="mx-auto bg-accentYellow-100 text-accentYellow-900" />
                        <p class="mt-2 text-xs font-semibold text-textPrimary">{{ $step['title'] }}</p>
                        <p class="mt-1 text-xs text-textSecondary">{{ $step['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- Product Narrative: Create / Enrich / Distribute --}}
@php
    $createNarrative = trans('public.landing.product_narrative.create');
    $enrichNarrative = trans('public.landing.product_narrative.enrich');
    $distributeNarrative = trans('public.landing.product_narrative.distribute');
@endphp
<section id="capabilities" class="border-t border-border">
    {{-- Create --}}
    <div class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-10 lg:grid-cols-[minmax(0,0.78fr)_minmax(0,1.22fr)] lg:items-start lg:gap-12 xl:gap-16">
                <div class="max-w-[26rem]">
                    <h2 class="pl-public-heading pl-public-heading-h2">{{ $createNarrative['title'] }}</h2>
                    <p class="mt-4 max-w-[22rem] text-base leading-relaxed text-textSecondary md:text-[1.0625rem]">{{ $createNarrative['description'] }}</p>

                    <ul class="mt-8 max-w-[22rem] space-y-4">
                        @foreach($createNarrative['items'] as $item)
                            <li class="flex items-start gap-3">
                                <span class="mt-1 flex h-[1.375rem] w-[1.375rem] shrink-0 items-center justify-center rounded-full bg-publicPrimary/8">
                                    <x-public.icon name="check" size="xs" class="text-publicPrimary" />
                                </span>
                                <span class="text-[14px] leading-[1.625rem] text-textPrimary">{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="flex items-center justify-center lg:justify-end">
                    <div class="pl-product-preview" aria-hidden="true">
                        <div class="pl-product-preview__frame">
                            <div class="pl-product-preview__bar">
                                <div class="pl-product-preview__dots">
                                    <span class="pl-product-preview__dot"></span>
                                    <span class="pl-product-preview__dot"></span>
                                    <span class="pl-product-preview__dot"></span>
                                </div>
                                <div class="pl-product-preview__tabs">
                                    <span class="pl-preview-chip pl-preview-chip-active">{{ $createNarrative['preview']['tab_brief'] }}</span>
                                    <span class="pl-preview-chip">{{ $createNarrative['preview']['tab_draft'] }}</span>
                                </div>
                                <span class="pl-preview-status">{{ $createNarrative['preview']['status'] }}</span>
                            </div>

                            <div class="pl-product-preview__body">
                                <div class="pl-preview-pane pl-preview-pane-main">
                                    <p class="pl-preview-kicker">{{ $createNarrative['preview']['window'] }}</p>

                                    <div>
                                        <p class="pl-preview-title">{{ $createNarrative['preview']['headline'] }}</p>
                                        <p class="pl-preview-meta">{{ $createNarrative['preview']['headline_meta'] }}</p>
                                    </div>

                                    <div class="pl-preview-panel">
                                        <div class="pl-preview-label-row">
                                            <span>{{ $createNarrative['preview']['brief_label'] }}</span>
                                            <span class="pl-preview-badge">{{ $createNarrative['preview']['brief_state'] }}</span>
                                        </div>
                                        <p class="pl-preview-copy">{{ $createNarrative['preview']['brief_value'] }}</p>
                                        <div class="pl-preview-inline-list">
                                            @foreach($createNarrative['preview']['outline_items'] as $item)
                                                <span class="pl-preview-inline-pill">{{ $item }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                                <div class="pl-preview-pane pl-preview-pane-side">
                                    <div class="pl-preview-panel">
                                        <p class="pl-preview-section-label">{{ $createNarrative['preview']['workflow_label'] }}</p>
                                        <div class="mt-3 space-y-2.5">
                                            @foreach(array_slice($createNarrative['preview']['workflow_steps'], 0, 2) as $index => $step)
                                                <div class="pl-preview-timeline">
                                                    <span class="pl-preview-step-dot {{ $index === 1 ? 'pl-preview-step-dot-active' : '' }}"></span>
                                                    <div>
                                                        <p class="pl-preview-step-title">{{ $step['label'] }}</p>
                                                        <p class="pl-preview-step-meta">{{ $step['meta'] }}</p>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="pl-preview-panel">
                                        <div class="pl-preview-label-row">
                                            <span>{{ $createNarrative['preview']['schedule_label'] }}</span>
                                            <span class="pl-preview-badge">{{ $createNarrative['preview']['schedule_value'] }}</span>
                                        </div>
                                        <div class="mt-3 space-y-2">
                                            <div class="pl-preview-bar"><span class="pl-preview-bar-fill" style="width: 72%"></span></div>
                                            <div class="pl-preview-bar"><span class="pl-preview-bar-fill" style="width: 52%"></span></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Enrich --}}
    <div class="pl-public-canvas">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-10 lg:grid-cols-[minmax(0,1.22fr)_minmax(0,0.78fr)] lg:items-start lg:gap-12 xl:gap-16">
                <div class="order-2 flex items-center justify-center lg:order-1 lg:justify-start">
                    <div class="pl-product-preview" aria-hidden="true">
                        <div class="pl-product-preview__frame">
                            <div class="pl-product-preview__bar">
                                <div class="pl-product-preview__dots">
                                    <span class="pl-product-preview__dot"></span>
                                    <span class="pl-product-preview__dot"></span>
                                    <span class="pl-product-preview__dot"></span>
                                </div>
                                <div class="pl-product-preview__tabs">
                                    <span class="pl-preview-chip pl-preview-chip-active">{{ $enrichNarrative['preview']['tab_context'] }}</span>
                                    <span class="pl-preview-chip">{{ $enrichNarrative['preview']['tab_suggestions'] }}</span>
                                </div>
                                <span class="pl-preview-status">{{ $enrichNarrative['preview']['status'] }}</span>
                            </div>

                            <div class="pl-product-preview__body">
                                <div class="pl-preview-pane pl-preview-pane-main">
                                    <p class="pl-preview-kicker">{{ $enrichNarrative['preview']['window'] }}</p>

                                    <div>
                                        <p class="pl-preview-title">{{ $enrichNarrative['preview']['headline'] }}</p>
                                        <p class="pl-preview-meta">{{ $enrichNarrative['preview']['headline_meta'] }}</p>
                                    </div>

                                    <div class="pl-preview-context-grid">
                                        @foreach($enrichNarrative['preview']['context_blocks'] as $contextBlock)
                                            <div class="pl-preview-context-card-compact">
                                                <p class="pl-preview-context-label">{{ $contextBlock['label'] }}</p>
                                                <p class="pl-preview-context-value">{{ $contextBlock['value'] }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="pl-preview-pane pl-preview-pane-side">
                                    <div class="pl-preview-panel">
                                        <p class="pl-preview-section-label">{{ $enrichNarrative['preview']['suggestions_label'] }}</p>
                                        <div class="pl-preview-stack-tight mt-3">
                                            @foreach(array_slice($enrichNarrative['preview']['suggestions'], 0, 2) as $suggestion)
                                                <div class="pl-preview-list-card">
                                                    <div>
                                                        <p class="pl-preview-list-title">{{ $suggestion['title'] }}</p>
                                                        <p class="pl-preview-list-meta">{{ $suggestion['meta'] }}</p>
                                                    </div>
                                                    <span class="pl-preview-score">{{ $suggestion['score'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="pl-preview-panel">
                                        <p class="pl-preview-section-label">{{ $enrichNarrative['preview']['quality_label'] }}</p>
                                        <div class="pl-preview-metric-grid">
                                            @foreach($enrichNarrative['preview']['quality_metrics'] as $metric)
                                                <div class="pl-preview-metric">
                                                    <p class="pl-preview-metric-label">{{ $metric['label'] }}</p>
                                                    <p class="pl-preview-metric-value">{{ $metric['value'] }}</p>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="order-1 max-w-[26rem] lg:order-2 lg:justify-self-end">
                    <h2 class="pl-public-heading pl-public-heading-h2">{{ $enrichNarrative['title'] }}</h2>
                    <p class="mt-4 max-w-[22rem] text-base leading-relaxed text-textSecondary md:text-[1.0625rem]">{{ $enrichNarrative['description'] }}</p>

                    <ul class="mt-8 max-w-[22rem] space-y-4">
                        @foreach($enrichNarrative['items'] as $item)
                            <li class="flex items-start gap-3">
                                <span class="mt-1 flex h-[1.375rem] w-[1.375rem] shrink-0 items-center justify-center rounded-full bg-publicPrimary/8">
                                    <x-public.icon name="check" size="xs" class="text-publicPrimary" />
                                </span>
                                <span class="text-[14px] leading-[1.625rem] text-textPrimary">{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Distribute --}}
    <div class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-10 lg:grid-cols-[minmax(0,0.78fr)_minmax(0,1.22fr)] lg:items-start lg:gap-12 xl:gap-16">
                <div class="max-w-[26rem]">
                    <h2 class="pl-public-heading pl-public-heading-h2">{{ $distributeNarrative['title'] }}</h2>
                    <p class="mt-4 max-w-[22rem] text-base leading-relaxed text-textSecondary md:text-[1.0625rem]">{{ $distributeNarrative['description'] }}</p>

                    <ul class="mt-8 max-w-[22rem] space-y-4">
                        @foreach($distributeNarrative['items'] as $item)
                            <li class="flex items-start gap-3">
                                <span class="mt-1 flex h-[1.375rem] w-[1.375rem] shrink-0 items-center justify-center rounded-full bg-publicPrimary/8">
                                    <x-public.icon name="check" size="xs" class="text-publicPrimary" />
                                </span>
                                <span class="text-[14px] leading-[1.625rem] text-textPrimary">{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="flex items-center justify-center lg:justify-end">
                    <div class="pl-product-preview" aria-hidden="true">
                        <div class="pl-product-preview__frame">
                            <div class="pl-product-preview__bar">
                                <div class="pl-product-preview__dots">
                                    <span class="pl-product-preview__dot"></span>
                                    <span class="pl-product-preview__dot"></span>
                                    <span class="pl-product-preview__dot"></span>
                                </div>
                                <div class="pl-product-preview__tabs">
                                    <span class="pl-preview-chip pl-preview-chip-active">{{ $distributeNarrative['preview']['tab_publish'] }}</span>
                                    <span class="pl-preview-chip">{{ $distributeNarrative['preview']['tab_impact'] }}</span>
                                </div>
                                <span class="pl-preview-status">{{ $distributeNarrative['preview']['status'] }}</span>
                            </div>

                            <div class="pl-product-preview__body">
                                <div class="pl-preview-pane pl-preview-pane-main">
                                    <p class="pl-preview-kicker">{{ $distributeNarrative['preview']['window'] }}</p>

                                    <div>
                                        <p class="pl-preview-title">{{ $distributeNarrative['preview']['headline'] }}</p>
                                        <p class="pl-preview-meta">{{ $distributeNarrative['preview']['headline_meta'] }}</p>
                                    </div>

                                    <div class="pl-preview-panel">
                                        <p class="pl-preview-section-label">{{ $distributeNarrative['preview']['channels_label'] }}</p>
                                        <div class="pl-preview-stack-tight mt-3">
                                            @foreach($distributeNarrative['preview']['channels'] as $channel)
                                                <div class="pl-preview-list-card">
                                                    <div>
                                                        <p class="pl-preview-list-title">{{ $channel['title'] }}</p>
                                                        <p class="pl-preview-list-meta">{{ $channel['meta'] }}</p>
                                                    </div>
                                                    <span class="pl-preview-badge">{{ $channel['state'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                                <div class="pl-preview-pane pl-preview-pane-side">
                                    <div class="pl-preview-panel">
                                        <p class="pl-preview-section-label">{{ $distributeNarrative['preview']['chain_label'] }}</p>
                                        <div class="pl-preview-chain-compact">
                                            @foreach($distributeNarrative['preview']['chain_nodes'] as $node)
                                                <div>
                                                    <div class="pl-preview-chain-node">
                                                        <span class="pl-preview-chain-dot"></span>
                                                        <span class="pl-preview-chain-pill">{{ $node }}</span>
                                                    </div>
                                                    @unless($loop->last)
                                                        <div class="pl-preview-chain-link"></div>
                                                    @endunless
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="pl-preview-panel">
                                        <p class="pl-preview-section-label">{{ $distributeNarrative['preview']['analytics_label'] }}</p>
                                        <div class="pl-preview-metric-grid">
                                            @foreach($distributeNarrative['preview']['analytics'] as $metric)
                                                <div class="pl-preview-metric">
                                                    <p class="pl-preview-metric-label">{{ $metric['label'] }}</p>
                                                    <p class="pl-preview-metric-value">{{ $metric['value'] }}</p>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- Governance --}}
<section id="governance" class="bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid items-center gap-8 md:grid-cols-2 md:gap-12 lg:gap-16">
            <div>
                <div class="mb-3 inline-flex items-center gap-2 text-xs font-semibold text-textSecondary">
                    <x-public.icon name="shield" size="xs" />
                    <span>{{ __('public.landing.gov_badge') }}</span>
                </div>

                <h2 class="pl-public-heading pl-public-heading-h2">{{ __('public.landing.gov_title') }}</h2>
                <p class="mt-3 text-sm leading-6 text-textSecondary md:text-base">{{ __('public.landing.gov_text') }}</p>

                <ul class="mt-6 space-y-3 text-sm text-textSecondary">
                    @foreach(trans('public.landing.gov_points') as $point)
                        <li class="flex gap-3">
                            <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-7">
                    <a href="#capabilities" class="inline-flex items-center justify-center gap-2 rounded-full border border-publicPrimary/18 bg-white px-6 py-3 text-sm font-semibold text-publicPrimary transition-colors hover:bg-[#f8fafc]">
                        {{ __('public.landing.gov_explore') }}
                        <x-public.icon name="arrow-right" size="xs" />
                    </a>
                </div>
            </div>

            <div class="pl-public-card-soft p-5 md:p-6">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-textPrimary">{{ __('public.landing.workflow_title') }}</p>
                    <span class="inline-flex items-center rounded-md border border-publicPrimary/12 bg-white px-3 py-1 text-xs font-medium text-publicPrimary">{{ __('public.landing.workflow_active') }}</span>
                </div>

                <div class="mt-5 space-y-3">
                    <div class="rounded-md border border-border bg-white p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-textPrimary">{{ __('public.landing.workflow_draft') }}</p>
                            <p class="text-xs text-textSecondary">10:23</p>
                        </div>
                        <p class="mt-1 text-xs text-textSecondary">{{ __('public.landing.workflow_auto') }}</p>
                    </div>

                    <div class="rounded-md border border-border bg-white p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-textPrimary">{{ __('public.landing.workflow_review') }}</p>
                            <span class="inline-flex items-center rounded-md border border-publicPrimary/12 bg-publicPrimary/8 px-3 py-1 text-xs font-medium text-publicPrimary">{{ __('public.landing.workflow_progress') }}</span>
                        </div>
                        <div class="mt-3 flex items-center gap-2">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-publicPrimary text-xs font-semibold text-textInverse">S</span>
                            <div>
                                <p class="text-xs font-semibold text-textPrimary">Sarah Jenkins</p>
                                <p class="text-xs text-textSecondary">{{ __('public.landing.workflow_editor') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-md border border-border bg-white p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-textPrimary">{{ __('public.landing.workflow_final') }}</p>
                            <span class="inline-flex items-center rounded-md border border-border bg-[#f8fafc] px-3 py-1 text-xs font-medium text-textSecondary">{{ __('public.landing.workflow_queued') }}</span>
                        </div>
                        <p class="mt-1 text-xs text-textSecondary">{{ __('public.landing.workflow_signoff') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- Trust (dark) --}}
<section class="border-y border-publicPrimary/10 bg-publicPrimary">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-8 md:grid-cols-2 md:items-center">
            <div>
                <h2 class="pl-public-heading pl-public-heading-h2 text-white">{{ __('public.landing.trust_title') }}</h2>
                <p class="mt-3 text-sm leading-6 text-white/76 md:text-base">{{ __('public.landing.trust_text') }}</p>
            </div>
            <ul class="space-y-3 text-sm text-white/86">
                @foreach(trans('public.landing.trust_points') as $point)
                    <li class="flex gap-3 rounded-md border border-white/10 bg-white/8 px-4 py-3">
                        <x-public.icon name="check" size="xs" class="mt-0.5 flex-none bg-white/10 text-white" />
                        <span>{{ $point }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</section>

{{-- Intelligence --}}
<section id="intelligence" class="border-y border-border bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mx-auto max-w-4xl pl-public-card-soft px-6 py-8 sm:px-8 md:px-10 md:py-10">
            <div class="mb-3 inline-flex items-center gap-2 text-xs font-semibold text-textSecondary">
                <x-public.icon name="database" size="xs" />
                <span>{{ __('public.landing.intel_badge') }}</span>
            </div>

            <h2 class="pl-public-heading pl-public-heading-h2">{{ __('public.landing.intel_title') }}</h2>
            <p class="mt-3 text-sm leading-6 text-textSecondary md:text-base">{{ __('public.landing.intel_text') }}</p>

            <div class="mt-8 grid gap-4 md:grid-cols-2">
                @foreach(trans('public.landing.insights') as $insight)
                    <div class="pl-public-card px-5 py-5">
                        <div class="flex gap-3">
                            <x-public.icon :name="$insight['icon']" size="md" class="mt-0.5" />
                            <div>
                                <p class="text-sm font-semibold text-textPrimary">{{ $insight['title'] }}</p>
                                <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $insight['text'] }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-8 max-w-2xl text-sm leading-6 text-textSecondary md:text-base">
                {{ __('public.landing.intel_note') }}
            </p>
        </div>
    </div>
</section>

{{-- Agentic Marketing --}}
<section class="border-y border-border bg-white">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-10 lg:grid-cols-[minmax(0,0.9fr)_minmax(20rem,1.1fr)] lg:items-center">
            <div>
                <div class="mb-3 inline-flex items-center gap-2 text-xs font-semibold text-textSecondary">
                    <x-public.icon name="workflow" size="xs" />
                    <span>{{ __('public.landing.agentic_badge') }}</span>
                </div>

                <h2 class="pl-public-heading pl-public-heading-h2">{{ __('public.landing.agentic_title') }}</h2>
                <p class="mt-4 text-sm leading-7 text-textSecondary md:text-base">{{ __('public.landing.agentic_text') }}</p>

                <ul class="mt-6 space-y-3 text-sm text-textSecondary">
                    @foreach (__('public.landing.agentic_points') as $point)
                        <li class="flex items-start gap-3">
                            <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-8">
                    <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.agentic-marketing') }}" class="pl-public-primary-button">
                        {{ __('public.landing.agentic_cta') }}
                        <x-public.icon name="arrow-right" size="xs" class="bg-white/10 text-white" />
                    </a>
                </div>
            </div>

            <div class="pl-public-card-soft p-5 md:p-6">
                <div class="rounded-md border border-publicPrimary/12 bg-white p-5">
                    <p class="pl-public-eyebrow">{{ __('public.landing.agentic_visual_eyebrow') }}</p>
                    <h3 class="mt-3 pl-public-heading pl-public-heading-h3">{{ __('public.landing.agentic_visual_title') }}</h3>

                    <div class="mt-5 space-y-3">
                        @foreach (__('public.landing.agentic_visual_steps') as $step)
                            <div class="rounded-md border border-border bg-[#fbfaf7] px-4 py-3">
                                <div class="flex items-start gap-3">
                                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-publicPrimary text-xs font-semibold text-white">{{ $loop->iteration }}</span>
                                    <div>
                                        <p class="pl-public-heading pl-public-heading-card text-sm">{{ $step['label'] }}</p>
                                        <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $step['text'] }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- AI Search --}}
<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="pl-public-cta-panel pl-public-cta-panel--split py-8 md:py-10">
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(18rem,0.85fr)] lg:items-center">
                <div class="text-white">
                    <span class="inline-flex items-center rounded-md border border-white/15 bg-white/10 px-3 py-1 text-xs font-medium tracking-wide text-white">
                        {{ __('public.landing.ai_search_badge') }}
                    </span>

                    <h2 class="mt-4 text-balance pl-public-heading pl-public-heading-h2 text-white lg:text-5xl">
                        {{ __('public.landing.ai_search_title') }}
                    </h2>

                    <p class="mt-4 max-w-2xl text-sm leading-7 text-white/82 md:text-base">
                        {{ __('public.landing.ai_search_text') }}
                    </p>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-white/74 md:text-base">
                        {{ __('public.landing.ai_search_text_2') }}
                    </p>

                    <ul class="mt-6 space-y-3">
                        @foreach (__('public.landing.ai_search_points') as $point)
                            <li class="flex items-start gap-3">
                                <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/16 text-[11px] font-semibold text-white">✓</span>
                                <span class="text-sm text-white/88 md:text-base">{{ $point }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-8 flex flex-wrap gap-3">
                        @if ($isEarlyAccess)
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.early-access.show', ['intent' => 'early_access']) }}" class="pl-public-cta-primary">
                                {{ __('public.nav.early_access') }}
                            </a>
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.contact') }}#contact-form" class="pl-public-cta-secondary">
                                {{ __('public.landing.ai_search_cta_demo') }}
                            </a>
                        @else
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('pricing') }}" class="pl-public-cta-primary">
                                {{ __('public.landing.ai_search_cta_pricing') }}
                            </a>
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.contact') }}#contact-form" class="pl-public-cta-secondary">
                                {{ __('public.landing.ai_search_cta_demo') }}
                            </a>
                        @endif
                    </div>
                </div>

                <div class="rounded-md border border-white/12 bg-white/8 p-5">
                    <div class="rounded-md bg-[#f8fafc] p-5 text-publicPrimary">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-publicPrimary/72">{{ __('public.landing.ai_search_visual_eyebrow') }}</p>
                        <h3 class="mt-3 text-xl font-semibold leading-tight text-publicPrimary">{{ __('public.landing.ai_search_visual_title') }}</h3>

                        <div class="mt-5 space-y-3">
                            @foreach (__('public.landing.ai_search_visual_steps') as $step)
                                <div class="rounded-md border border-publicPrimary/12 bg-white px-4 py-3">
                                    <div class="flex items-start gap-3">
                                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-publicPrimary text-xs font-semibold text-white">{{ $loop->iteration }}</span>
                                        <div>
                                            <p class="text-sm font-semibold text-publicPrimary">{{ $step['label'] }}</p>
                                            <p class="mt-1 text-sm leading-6 text-publicPrimary/82">{{ $step['text'] }}</p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <p class="mt-5 text-sm leading-6 text-publicPrimary/80">
                            {{ __('public.landing.ai_search_visual_note') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- Integrations --}}
<section class="pl-public-canvas">
    @php($integrationTargets = array_values(__('public.landing.integration_targets')))
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <h2 class="text-center pl-public-heading pl-public-heading-h2">{{ __('public.landing.integration_title') }}</h2>

        <div class="pl-integration-visual">
            <div class="pl-integration-flow-layer hidden md:block" aria-hidden="true">
                <span class="pl-integration-flow-line pl-integration-flow-line-left"></span>
                <span class="pl-integration-flow-line pl-integration-flow-line-right"></span>
                <span class="pl-integration-flow-packet pl-integration-flow-packet-left pl-integration-flow-packet-1"></span>
                <span class="pl-integration-flow-packet pl-integration-flow-packet-right pl-integration-flow-packet-2"></span>
                <span class="pl-integration-flow-packet pl-integration-flow-packet-left pl-integration-flow-packet-3"></span>
            </div>

            <div class="pl-integration-stack grid gap-8 md:grid-cols-3 md:gap-6">
                <article class="pl-integration-column pl-integration-step">
                    <div class="pl-integration-node-row">
                        <span class="pl-integration-anchor-icon">
                            <x-brand-logo :show-text="false" />
                        </span>
                    </div>
                    <div class="pl-integration-copy">
                        <p class="text-sm font-semibold text-textPrimary">{{ \App\Support\Brand::product() }}</p>
                        <p class="mt-1 text-xs leading-5 text-textSecondary">{{ __('public.landing.integration_layer') }}</p>
                    </div>
                </article>

                <article class="pl-integration-column pl-integration-column-api pl-integration-step">
                    <div class="pl-integration-node-row">
                        <span class="pl-integration-hub-badge">{{ __('public.landing.integration_api_label') }}</span>
                    </div>
                    <div class="pl-integration-copy">
                        <p class="text-sm font-semibold text-textPrimary">{{ __('public.landing.integration_api_label') }}</p>
                        <p class="mt-1 text-xs leading-5 text-textSecondary">{{ __('public.landing.integration_delivery') }}</p>
                    </div>
                </article>

                <div class="pl-integration-target-slot pl-integration-step" data-integration-targets>
                    <div class="relative min-h-[9.5rem]">
                        @foreach ($integrationTargets as $target)
                            <div
                                class="pl-integration-target-card {{ $loop->first ? 'is-active' : '' }}"
                                data-integration-target
                                @if (! $loop->first) hidden @endif
                                aria-hidden="{{ $loop->first ? 'false' : 'true' }}"
                            >
                                <div class="pl-integration-column">
                                    <div class="pl-integration-node-row">
                                        <span class="pl-integration-anchor-icon">
                                            {{ $target['icon'] }}
                                        </span>
                                    </div>
                                    <div class="pl-integration-copy">
                                        <p class="text-sm font-semibold text-textPrimary">{{ $target['title'] }}</p>
                                        <p class="mt-1 text-xs leading-5 text-textSecondary">{{ $target['subtitle'] }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <p class="mx-auto mt-5 max-w-3xl text-center text-sm leading-6 text-textSecondary md:mt-8">{{ __('public.landing.integration_text') }}</p>
    </div>
</section>

{{-- Bottom CTA --}}
<section id="cta" class="bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="pl-public-cta-panel">
            <h2 class="pl-public-heading pl-public-heading-h2 text-white">{{ __('public.landing.cta_title') }}</h2>
            <p class="mx-auto mt-3 max-w-2xl text-sm text-white/76 md:text-base">{{ __('public.landing.cta_text') }}</p>

            <div class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ MarketingNavigation::buildUrl($bottomCta['primary']) }}" class="pl-public-cta-primary">{{ $bottomCta['primary']['label'] }}</a>
                <a href="{{ MarketingNavigation::buildUrl($bottomCta['secondary']) }}" class="pl-public-cta-secondary">{{ $bottomCta['secondary']['label'] }}</a>
            </div>
        </div>
    </div>
</section>

@include('public.partials.footer')

<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.lucide) {
            lucide.createIcons();
        }

        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        if (prefersReducedMotion.matches) {
            return;
        }

        const mobileIntegration = window.matchMedia('(max-width: 767px)');

        document.querySelectorAll('[data-integration-targets]').forEach((container) => {
            const targets = Array.from(container.querySelectorAll('[data-integration-target]'));
            if (targets.length < 2) {
                return;
            }

            if (mobileIntegration.matches) {
                targets.forEach((target, index) => {
                    const isActive = index === 0;
                    target.hidden = !isActive;
                    target.classList.toggle('is-active', isActive);
                    target.classList.toggle('is-inactive', !isActive);
                    target.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                });

                return;
            }

            let activeIndex = 0;

            const showTarget = (nextIndex) => {
                targets.forEach((target, index) => {
                    const isActive = index === nextIndex;
                    target.hidden = false;
                    target.classList.toggle('is-active', isActive);
                    target.classList.toggle('is-inactive', !isActive);
                    target.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                });

                window.setTimeout(() => {
                    targets.forEach((target, index) => {
                        if (index !== nextIndex) {
                            target.hidden = true;
                        }
                    });
                }, 360);

                activeIndex = nextIndex;
            };

            window.setInterval(() => {
                showTarget((activeIndex + 1) % targets.length);
            }, 4500);
        });
    });
</script>
</body>
</html>
