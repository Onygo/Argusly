<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @include('public.partials.seo-head', [
        'metaTitle' => $metaTitle ?? __('public.landing.pricing_meta_title'),
        'metaDescription' => $metaDescription ?? __('public.landing.pricing_meta_description'),
        'canonicalUrl' => $canonicalUrl ?? null,
        'hreflangUrls' => $hreflangUrls ?? [],
        'ogType' => 'website',
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

@php
    $locale = app()->getLocale();
    $comparison = (array) ($pageContent['comparison'] ?? []);
    $teamWorkflow = (array) ($pageContent['team_workflow'] ?? []);
    $roi = (array) ($pageContent['roi'] ?? []);
    $canSelfRegister = (bool) config('argusly.launch.public_registration_enabled', true)
        && ! (bool) config('argusly.launch.soft_launch_mode', false);
    $pricingCopy = $locale === 'nl'
        ? [
            'eyebrow' => 'Argusly Platform',
            'headline' => 'Eenvoudige pricing voor autonome marketing',
            'subheadline' => 'Start met het Argusly Platform. Schaal met credits. Voeg sites toe wanneer je organisatie groeit.',
            'supporting' => 'Een abonnement voor platformtoegang, AI Visibility, Opportunity Discovery, contentgeneratie, publishing, automations en reporting.',
            'primary_cta' => 'Start abonnement',
            'pilot_cta' => 'Pilot aanvragen',
            'secondary_cta' => 'Talk to Sales',
            'hero_card_title' => 'Een platform. Twee schaalfactoren.',
            'hero_card_body' => 'Geen Creator, Growth of Scale tiers. Argusly schaalt met credits en sites.',
            'credit_line' => '250 maandelijkse credits inbegrepen',
            'site_line' => '1 site inbegrepen, extra sites EUR 29 per maand',
            'pack_line' => 'Credit packs voor tijdelijke pieken',
            'section_title' => 'Argusly Platform',
            'section_body' => 'Alles wat je nodig hebt om autonome marketingoperaties te draaien, met credits als primaire schaalfactor.',
            'features' => ['250 monthly credits', '1 site', '5 users', 'AI Visibility', 'Opportunity Discovery', 'Content Generation', 'Publishing', 'Automations', 'Reporting'],
            'scale_title' => 'Schaal je operatie',
            'extra_site_title' => 'Extra Site',
            'extra_site_price' => '€29/maand per site',
            'extra_site_body' => 'Track AI visibility, opportunities en content performance voor extra merken, domeinen of landensites.',
            'credit_packs_title' => 'Credit Packs',
            'credit_packs_body' => 'Tijdelijk extra capaciteit nodig? Koop extra credits zonder je abonnement te wijzigen.',
            'credits_title' => 'Wat zijn credits?',
            'credits_body' => 'Credits voeden het werk dat Argusly uitvoert: AI visibility analyses, opportunity discovery, contentgeneratie, refresh workflows en publishing automation.',
            'team_title' => 'Gebouwd voor content operations en AI visibility',
            'team_body' => 'Argusly is geen losse AI writer, maar een systeem voor planning, zichtbaarheid, productie, governance en publishing.',
            'final_title' => 'Breng content operations onder in één schaalbaar systeem.',
            'final_body' => 'Start met het Argusly Platform en schaal met credits, sites en automation wanneer dat nodig is.',
            'faq' => [
                ['question' => 'Is Argusly alleen een AI writer?', 'answer' => 'Nee. Argusly combineert AI visibility, opportunity discovery, content operations, publishing en reporting in één platform.'],
                ['question' => 'Hoe werken credits?', 'answer' => 'Credits worden gebruikt voor werk dat Argusly uitvoert, zoals analyses, opportunities, contentgeneratie, refresh workflows en publishing automation.'],
                ['question' => 'Wat gebeurt er als mijn credits op zijn?', 'answer' => 'Je kunt een credit pack kopen voor tijdelijke extra capaciteit zonder je abonnement te wijzigen.'],
                ['question' => 'Kan ik meer sites toevoegen?', 'answer' => 'Ja. Eén site is inbegrepen. Extra sites kosten €29 per maand per site.'],
                ['question' => 'Kan mijn team samenwerken?', 'answer' => 'Ja. Het platformabonnement bevat 5 gebruikers en is ingericht voor samenwerking rond planning, content en publicatie.'],
                ['question' => 'Kan ik direct naar WordPress en LinkedIn publiceren?', 'answer' => 'Ja. Argusly ondersteunt publishing workflows voor WordPress en LinkedIn.'],
                ['question' => 'Verlopen ongebruikte credits?', 'answer' => 'Maandelijkse credits volgen de abonnementsregels. Gekochte credit packs zijn 12 maanden geldig.'],
                ['question' => 'Worden credit packs gedeeld binnen teams?', 'answer' => 'Ja. Gekochte credit packs zijn beschikbaar voor de organisatiecapaciteit en kunnen over workflows en sites worden ingezet.'],
                ['question' => 'Is er een agency- of enterprise-optie?', 'answer' => 'Ja. Enterprise is beschikbaar voor agencies, veel sites, SSO, SLA, governance en maatwerkafspraken.'],
            ],
        ]
        : [
            'eyebrow' => 'Argusly Platform',
            'headline' => 'Simple pricing for autonomous marketing',
            'subheadline' => 'Start with the Argusly Platform. Scale with credits. Add sites when your operation grows.',
            'supporting' => 'One subscription for platform access, AI Visibility, Opportunity Discovery, content generation, publishing, automations and reporting.',
            'primary_cta' => 'Start subscription',
            'pilot_cta' => 'Request a pilot',
            'secondary_cta' => 'Talk to Sales',
            'hero_card_title' => 'One platform. Two scale factors.',
            'hero_card_body' => 'No Creator, Growth or Scale tiers. Argusly scales with credits and sites.',
            'credit_line' => '250 monthly credits included',
            'site_line' => '1 site included, extra sites EUR 29 per month',
            'pack_line' => 'Credit packs for temporary peaks',
            'section_title' => 'Argusly Platform',
            'section_body' => 'Everything needed to run autonomous marketing operations, with credits as the primary scale factor.',
            'features' => ['250 monthly credits', '1 site', '5 users', 'AI Visibility', 'Opportunity Discovery', 'Content Generation', 'Publishing', 'Automations', 'Reporting'],
            'scale_title' => 'Scale your operation',
            'extra_site_title' => 'Extra Site',
            'extra_site_price' => '€29/month per site',
            'extra_site_body' => 'Track AI visibility, opportunities and content performance for additional brands, domains or country websites.',
            'credit_packs_title' => 'Credit Packs',
            'credit_packs_body' => 'Need temporary capacity? Purchase additional credits without changing your subscription.',
            'credits_title' => 'What are credits?',
            'credits_body' => 'Credits power the work Argusly performs, including AI visibility analysis, opportunity discovery, content generation, refresh workflows and publishing automation.',
            'team_title' => 'Built for content operations and AI visibility',
            'team_body' => 'Argusly is not just an AI writer. It is a system for planning, visibility, production, governance and publishing.',
            'final_title' => 'Move content operations into one scalable system.',
            'final_body' => 'Start with the Argusly Platform and scale with credits, sites and automation when needed.',
            'faq' => [
                ['question' => 'Is Argusly just an AI writer?', 'answer' => 'No. Argusly combines AI visibility, opportunity discovery, content operations, publishing and reporting in one platform.'],
                ['question' => 'How do credits work?', 'answer' => 'Credits are used for work Argusly performs, including analysis, opportunities, content generation, refresh workflows and publishing automation.'],
                ['question' => 'What happens when I run out of credits?', 'answer' => 'You can buy a credit pack for temporary extra capacity without changing your subscription.'],
                ['question' => 'Can I add more sites?', 'answer' => 'Yes. One site is included. Extra sites cost €29 per month per site.'],
                ['question' => 'Can my team collaborate?', 'answer' => 'Yes. The platform subscription includes 5 users and supports collaboration around planning, content and publishing.'],
                ['question' => 'Can I publish directly to WordPress and LinkedIn?', 'answer' => 'Yes. Argusly supports publishing workflows for WordPress and LinkedIn.'],
                ['question' => 'Do unused credits expire?', 'answer' => 'Monthly credits follow the subscription rules. Purchased credit packs are valid for 12 months.'],
                ['question' => 'Are credit packs shared across teams?', 'answer' => 'Yes. Purchased credit packs are available as organization capacity and can be used across workflows and sites.'],
                ['question' => 'Is there an agency or enterprise option?', 'answer' => 'Yes. Enterprise is available for agencies, many sites, SSO, SLA, governance and custom agreements.'],
            ],
        ];
    $simplifiedFaqItems = collect($pricingCopy['faq'])->map(fn (array $item): object => (object) $item);
    $contactHref = \App\Support\LocalizedMarketingUrl::route('public.contact', ['subject' => 'enterprise-pricing'], $locale) . '#contact-form';
    $pilotHref = \App\Support\LocalizedMarketingUrl::route('public.contact', ['subject' => 'pilot-aanvraag'], $locale) . '#contact-form';
    $subscribeHref = $canSelfRegister
        ? route('register', ['plan' => 'argusly_platform'])
        : $pilotHref;
    $registerHref = $subscribeHref;
    $plansCollection = collect($plans ?? [])->sortBy('sort_order')->values();
    $creditPacksCollection = collect($creditPacks ?? [])->sortBy('sort_order')->values();
    $formatCurrency = function (?int $amountCents, string $currency = 'EUR'): string {
        if ($amountCents === null) {
            return '';
        }

        $amount = number_format($amountCents / 100, 0);

        return strtoupper($currency) === 'EUR' ? '€' . $amount : strtoupper($currency) . ' ' . $amount;
    };
@endphp

<main class="bg-background" data-page="pricing">
    <section class="pl-public-hero">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-24">
            <div class="grid gap-10 lg:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)] lg:items-end">
                <div class="max-w-3xl">
                    <span class="pl-public-hero-label">
                        {{ $pricingCopy['eyebrow'] }}
                    </span>
                    <h1 class="mt-5 pl-public-heading pl-public-heading-hero">
                        {{ $pricingCopy['headline'] }}
                    </h1>
                    <p class="mt-5 max-w-2xl text-lg leading-8 text-textPrimary">
                        {{ $pricingCopy['subheadline'] }}
                    </p>
                    <p class="mt-4 max-w-2xl text-base leading-7 text-textSecondary">
                        {{ $pricingCopy['supporting'] }}
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ $subscribeHref }}" class="pl-public-primary-button">
                            {{ $pricingCopy['primary_cta'] }}
                        </a>
                        <a href="{{ $pilotHref }}" class="pl-public-secondary-button">
                            {{ $pricingCopy['pilot_cta'] }}
                        </a>
                    </div>
                </div>

                <div class="rounded-md border border-border/80 bg-white p-6 sm:p-7">
                    <p class="text-sm font-semibold text-textPrimary">{{ $pricingCopy['hero_card_title'] }}</p>
                    <p class="mt-3 text-sm leading-6 text-textSecondary">
                        {{ $pricingCopy['hero_card_body'] }}
                    </p>
                    <div class="mt-6 space-y-3 border-t border-border/70 pt-6">
                        <div class="flex items-start gap-3 text-sm">
                            <x-public.icon name="refresh-cw" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                            <span>{{ $pricingCopy['credit_line'] }}</span>
                        </div>
                        <div class="flex items-start gap-3 text-sm">
                            <x-public.icon name="globe" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                            <span>{{ $pricingCopy['site_line'] }}</span>
                        </div>
                        <div class="flex items-start gap-3 text-sm">
                            <x-public.icon name="zap" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                            <span>{{ $pricingCopy['pack_line'] }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="plans" class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="mb-10 max-w-3xl">
                <h2 class="pl-public-heading pl-public-heading-h2">{{ $pricingCopy['section_title'] }}</h2>
                <p class="mt-4 text-base leading-7 text-textSecondary">
                    {{ $pricingCopy['section_body'] }}
                </p>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                <article data-pricing-card class="relative flex h-full flex-col rounded-md border border-publicPrimary bg-[#f8fafc] p-7">
                    <p class="pl-public-eyebrow">Platform subscription</p>
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h3">Argusly Platform</h2>
                    <div class="mt-8">
                        <div class="flex items-end gap-2">
                            <span class="pl-public-heading text-5xl text-textPrimary">€99</span>
                            <span class="pb-1 text-sm text-textSecondary">/ month</span>
                        </div>
                        <p class="mt-4 text-sm font-medium text-textPrimary">250 credits / month</p>
                    </div>
                    <div class="mt-6 rounded-md border border-border/70 bg-white px-4 py-4">
                        <p class="pl-public-eyebrow">Platform access</p>
                        <div class="mt-3 grid grid-cols-2 gap-3 text-sm text-textSecondary">
                            <div>
                                <p class="font-medium text-textPrimary">1</p>
                                <p>Site included</p>
                            </div>
                            <div>
                                <p class="font-medium text-textPrimary">5</p>
                                <p>Users</p>
                            </div>
                        </div>
                        <p class="mt-3 border-t border-border/60 pt-3 text-xs leading-5 text-textMuted">
                            Extra sites €29 / month each.
                        </p>
                    </div>
                    <ul class="mt-7 grid gap-3.5 text-sm leading-6 text-textSecondary sm:grid-cols-2 lg:grid-cols-1">
                        @foreach($pricingCopy['features'] as $feature)
                            <li class="flex items-start gap-3">
                                <x-public.icon name="check" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                                <span>{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-auto grid gap-3 pt-8 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                        <a href="{{ $subscribeHref }}" class="pl-public-primary-button justify-center">
                            {{ $pricingCopy['primary_cta'] }}
                        </a>
                        <a href="{{ $pilotHref }}" class="pl-public-secondary-button justify-center">
                            {{ $pricingCopy['pilot_cta'] }}
                        </a>
                    </div>
                </article>

                <article class="pl-public-card-compact p-7">
                    <p class="pl-public-eyebrow">{{ $pricingCopy['scale_title'] }}</p>

                    <div class="mt-6 rounded-md border border-border/70 bg-white p-5">
                        <h3 class="pl-public-heading pl-public-heading-h3">{{ $pricingCopy['extra_site_title'] }}</h3>
                        <p class="mt-3 pl-public-heading text-3xl text-textPrimary">
                            €29 <span class="text-sm font-medium text-textSecondary">{{ $locale === 'nl' ? '/maand per site' : '/month per site' }}</span>
                        </p>
                        <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $pricingCopy['extra_site_body'] }}</p>
                    </div>

                    <div class="mt-5 rounded-md border border-border/70 bg-white p-5">
                        <h3 class="pl-public-heading pl-public-heading-h3">{{ $pricingCopy['credit_packs_title'] }}</h3>
                        <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $pricingCopy['credit_packs_body'] }}</p>
                        <div class="mt-5 grid gap-3 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
                            @forelse($creditPacksCollection as $pack)
                                <div class="rounded-md border border-border/70 bg-[#f8fafc] p-4">
                                    <p class="text-sm font-semibold text-textPrimary">{{ number_format((int) ($pack['credits'] ?? 0)) }} credits</p>
                                    <p class="mt-1 text-sm text-textSecondary">{{ $formatCurrency($pack['price_cents'] ?? 0, (string) ($pack['currency'] ?? 'EUR')) }}</p>
                                </div>
                            @empty
                                <div class="rounded-md border border-border/70 bg-[#f8fafc] p-4">
                                    <p class="text-sm font-semibold text-textPrimary">100 credits</p>
                                    <p class="mt-1 text-sm text-textSecondary">€39</p>
                                </div>
                                <div class="rounded-md border border-border/70 bg-[#f8fafc] p-4">
                                    <p class="text-sm font-semibold text-textPrimary">500 credits</p>
                                    <p class="mt-1 text-sm text-textSecondary">€179</p>
                                </div>
                                <div class="rounded-md border border-border/70 bg-[#f8fafc] p-4">
                                    <p class="text-sm font-semibold text-textPrimary">1,000 credits</p>
                                    <p class="mt-1 text-sm text-textSecondary">€329</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </article>
            </div>

            @if($enterprisePlan)
                <section class="mt-10 lg:mt-14" data-enterprise-block>
                    <article class="overflow-hidden rounded-md border border-border/80 bg-textPrimary text-white">
                        <div class="bg-gradient-to-r from-white/[0.04] via-transparent to-transparent px-8 py-8 sm:px-10 sm:py-10 xl:px-12">
                            <div class="flex flex-col gap-10 xl:flex-row xl:items-start xl:justify-between">
                                <div class="max-w-2xl">
                                    <span class="inline-flex items-center rounded-md border border-white/15 bg-white/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-white/90">
                                        {{ $enterprisePlan['badge'] ?? 'Enterprise' }}
                                    </span>
                                    <h2 class="mt-5 pl-public-heading pl-public-heading-h2 text-white">
                                        {{ $enterprisePlan['price_label'] ?? 'Custom pricing' }}
                                    </h2>
                                    <p class="mt-4 text-lg leading-8 text-white/88">
                                        {{ $enterprisePlan['audience'] ?? $enterprisePlan['description'] }}
                                    </p>
                                    <p class="mt-4 max-w-xl text-sm leading-7 text-white/72">
                                        {{ $enterprisePlan['body'] ?? ($enterprisePlan['name'] . ' is built for organizations that want tailored governance, autonomy rules, shared team operations, and product extensions on request.') }}
                                    </p>
                                    <div class="mt-7">
                                        <a href="{{ $enterprisePlan['cta_url'] }}" class="inline-flex items-center justify-center rounded-full bg-white px-5 py-3 text-sm font-semibold text-textPrimary transition-colors hover:bg-white/90">
                                            {{ $enterprisePlan['cta_label'] ?: 'Plan enterprise rollout' }}
                                        </a>
                                    </div>
                                </div>

                                <div class="w-full xl:max-w-2xl">
                                    <ul class="grid gap-x-8 gap-y-4 text-sm leading-6 text-white/84 md:grid-cols-2">
                                        @foreach((array) ($enterprisePlan['features'] ?? []) as $feature)
                                            <li class="flex items-start gap-3">
                                                <x-public.icon name="check" size="xs" class="mt-0.5 flex-none bg-white text-textPrimary" />
                                                <span>{{ $feature }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </article>
                </section>
            @endif
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <h2 class="pl-public-heading pl-public-heading-h2">
                    {{ $comparison['title'] ?? 'More than AI writing' }}
                </h2>
                <p class="mt-4 text-base leading-7 text-textSecondary">
                    {{ $comparison['subtitle'] ?? 'Argusly helps teams manage the full content lifecycle from planning to publishing and AI discoverability.' }}
                </p>
            </div>

            <div class="mt-10 hidden overflow-hidden rounded-md border border-border/80 bg-white md:block">
                <div class="grid grid-cols-[minmax(0,1.4fr)_180px_180px] border-b border-border/70 bg-[#f8fafc] px-6 py-4 text-sm font-semibold text-textPrimary">
                    <div>Capabilities</div>
                    <div class="text-center">{{ $comparison['left_label'] ?? 'Argusly' }}</div>
                    <div class="text-center">{{ $comparison['right_label'] ?? 'Traditional AI writers' }}</div>
                </div>
                @foreach((array) ($comparison['rows'] ?? []) as $row)
                    <div class="grid grid-cols-[minmax(0,1.4fr)_180px_180px] items-center border-b border-border/60 px-6 py-4 text-sm last:border-b-0">
                        <div class="text-textPrimary">{{ $row['label'] ?? '' }}</div>
                        <div class="flex justify-center">
                            <x-public.icon name="{{ !empty($row['argusly']) ? 'check' : 'minus' }}" size="xs" class="{{ !empty($row['argusly']) ? 'text-publicPrimary' : 'text-textMuted' }}" />
                        </div>
                        <div class="flex justify-center">
                            <x-public.icon name="{{ !empty($row['alternative']) ? 'check' : 'minus' }}" size="xs" class="{{ !empty($row['alternative']) ? 'text-textPrimary' : 'text-textMuted' }}" />
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-8 space-y-3 md:hidden">
                @foreach((array) ($comparison['rows'] ?? []) as $row)
                    <article class="rounded-md border border-border/80 bg-white p-4">
                        <h3 class="text-base font-semibold leading-6 text-textPrimary">{{ $row['label'] ?? '' }}</h3>
                        <div class="mt-4 grid gap-3">
                            <div class="flex items-center justify-between gap-4 rounded-md border border-border/70 bg-[#f8fafc] px-3 py-3">
                                <span class="text-sm font-medium text-textPrimary">{{ $comparison['left_label'] ?? 'Argusly' }}</span>
                                <x-public.icon name="{{ !empty($row['argusly']) ? 'check' : 'minus' }}" size="xs" class="{{ !empty($row['argusly']) ? 'text-publicPrimary' : 'text-textMuted' }}" />
                            </div>
                            <div class="flex items-center justify-between gap-4 rounded-md border border-border/70 bg-[#f8fafc] px-3 py-3">
                                <span class="text-sm font-medium text-textPrimary">{{ $comparison['right_label'] ?? 'Traditional AI writers' }}</span>
                                <x-public.icon name="{{ !empty($row['alternative']) ? 'check' : 'minus' }}" size="xs" class="{{ !empty($row['alternative']) ? 'text-textPrimary' : 'text-textMuted' }}" />
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-16 sm:px-6 lg:grid-cols-2 md:py-20">
            <div class="rounded-md border border-border/80 bg-[#f8fafc] p-7 sm:p-8">
                <h2 class="pl-public-heading pl-public-heading-h2">{{ $teamWorkflow['title'] ?? $pricingCopy['team_title'] }}</h2>
                <p class="mt-4 text-base leading-7 text-textSecondary">{{ $teamWorkflow['subtitle'] ?? $pricingCopy['team_body'] }}</p>
                <ul class="mt-6 space-y-4 text-sm text-textSecondary">
                    @foreach((array) ($teamWorkflow['points'] ?? []) as $point)
                        <li class="flex items-start gap-3">
                            <x-public.icon name="check" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="rounded-md border border-border/80 bg-textPrimary p-7 text-white sm:p-8">
                <h2 class="pl-public-heading pl-public-heading-h2 text-white">{{ $roi['title'] ?? ($locale === 'nl' ? 'Van losse AI-output naar governed execution' : 'From isolated AI output to governed execution') }}</h2>
                <ul class="mt-6 grid gap-4 sm:grid-cols-2">
                    @foreach((array) ($roi['items'] ?? []) as $item)
                        <li class="rounded-md border border-white/10 bg-white/5 px-4 py-4 text-sm text-white/82">
                            {{ $item }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    <x-public.faq-section
        :items="$simplifiedFaqItems"
        :locale="$locale"
        :emit-schema="false"
    />

    <section class="bg-publicPrimary">
        <div class="mx-auto max-w-5xl px-4 py-16 text-center sm:px-6 md:py-20">
            <h2 class="pl-public-heading pl-public-heading-h2 text-white">
                {{ $pricingCopy['final_title'] }}
            </h2>
            <p class="mx-auto mt-4 max-w-3xl text-base leading-7 text-white/80">
                {{ $pricingCopy['final_body'] }}
            </p>
            <div class="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                <a href="{{ $registerHref }}" class="inline-flex items-center justify-center rounded-full bg-white px-6 py-3 text-sm font-semibold text-publicPrimary transition-colors hover:bg-white/90">
                    {{ $pricingCopy['primary_cta'] }}
                </a>
                <a href="{{ $pilotHref }}" class="inline-flex items-center justify-center rounded-full border border-white/20 bg-white/10 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/20">
                    {{ $pricingCopy['pilot_cta'] }}
                </a>
            </div>
        </div>
    </section>
</main>

@include('public.partials.footer')

</body>
</html>
