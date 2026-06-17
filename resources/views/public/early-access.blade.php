<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @include('public.partials.seo-head', [
        'metaTitle' => $metaTitle ?? __('public.early_access.meta_title'),
        'metaDescription' => $metaDescription ?? __('public.early_access.meta_description'),
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
    $activeIntent = in_array((string) ($intent ?? 'early_access'), ['early_access', 'demo'], true)
        ? (string) $intent
        : 'early_access';
    $isDemoIntent = $activeIntent === 'demo';
@endphp

<main class="bg-background">
    {{-- Hero --}}
    <section class="pl-public-hero">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <span class="pl-public-hero-label">
                    {{ __('public.early_access.badge') }}
                </span>
                <h1 class="mt-4 pl-public-heading pl-public-heading-hero">
                    {{ $isDemoIntent ? __('public.early_access.title_demo') : __('public.early_access.title_early_access') }}
                </h1>
                <p class="mt-4 max-w-2xl text-pretty text-sm leading-6 text-textSecondary md:text-base">
                    {{ $isDemoIntent ? __('public.early_access.description_demo') : __('public.early_access.description_early_access') }}
                </p>
            </div>
        </div>
    </section>

    {{-- Form --}}
    <section class="bg-background">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-8 md:grid-cols-3">
                <div class="md:col-span-1">
                    <div class="pl-public-card p-6">
                        <p class="text-sm font-semibold text-textPrimary">{{ __('public.early_access.choose_request_type') }}</p>
                        <div class="mt-4 flex flex-col gap-2">
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.early-access.show', ['intent' => 'early_access']) }}" class="inline-flex items-center justify-center rounded-md px-4 py-2.5 text-sm font-medium transition-colors {{ $isDemoIntent ? 'border border-border bg-white text-textSecondary hover:bg-[#f8fafc]' : 'bg-publicPrimary text-white' }}">
                                {{ __('public.early_access.request_early_access') }}
                            </a>
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.early-access.show', ['intent' => 'demo']) }}" class="inline-flex items-center justify-center rounded-md px-4 py-2.5 text-sm font-medium transition-colors {{ $isDemoIntent ? 'bg-publicPrimary text-white' : 'border border-border bg-white text-textSecondary hover:bg-[#f8fafc]' }}">
                                {{ __('public.early_access.book_demo') }}
                            </a>
                        </div>
                        <ul class="mt-5 space-y-3 text-sm text-textSecondary">
                            <li class="flex gap-3"><x-public.icon name="check" size="xs" class="mt-0.5 flex-none" /><span>{{ __('public.early_access.feature_production') }}</span></li>
                            <li class="flex gap-3"><x-public.icon name="check" size="xs" class="mt-0.5 flex-none" /><span>{{ __('public.early_access.feature_onboarding') }}</span></li>
                            <li class="flex gap-3"><x-public.icon name="check" size="xs" class="mt-0.5 flex-none" /><span>{{ __('public.early_access.feature_support') }}</span></li>
                        </ul>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <div class="pl-public-card p-6">
                        @if (session('early_access_status'))
                            <div class="rounded-md border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800">
                                {{ session('early_access_status') }}
                            </div>
                        @endif

                        @if ($errors->any())
                            <div class="mt-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">
                                {{ $errors->first() }}
                            </div>
                        @endif

                        <form method="POST" action="{{ \App\Support\LocalizedMarketingUrl::route('public.early-access.store') }}" class="mt-4 grid gap-4 md:grid-cols-2">
                            @csrf
                            <input type="hidden" name="intent" value="{{ old('intent', $activeIntent) }}">
                            <input type="hidden" name="utm_source" value="{{ old('utm_source', request('utm_source')) }}">
                            <input type="hidden" name="utm_medium" value="{{ old('utm_medium', request('utm_medium')) }}">
                            <input type="hidden" name="utm_campaign" value="{{ old('utm_campaign', request('utm_campaign')) }}">
                            <input type="text" name="company_size" value="{{ old('company_size') }}" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.early_access.field_full_name') }}</label>
                                <input type="text" name="full_name" value="{{ old('full_name') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" required maxlength="120">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.early_access.field_work_email') }}</label>
                                <input type="email" name="work_email" value="{{ old('work_email') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" required maxlength="190">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.early_access.field_phone') }}</label>
                                <input type="text" name="phone" value="{{ old('phone') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" maxlength="60">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.early_access.field_job_title') }}</label>
                                <input type="text" name="job_title" value="{{ old('job_title') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" maxlength="160">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.early_access.field_company') }}</label>
                                <input type="text" name="company" value="{{ old('company') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" required maxlength="190">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.early_access.field_company_size') }}</label>
                                <input type="text" name="company_size_visible" value="{{ old('company_size_visible') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" maxlength="80">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.early_access.field_industry') }}</label>
                                <input type="text" name="industry" value="{{ old('industry') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" maxlength="160">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.early_access.field_country') }}</label>
                                <input type="text" name="country" value="{{ old('country') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" maxlength="120">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.early_access.field_website') }}</label>
                                <input type="text" name="website" value="{{ old('website') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" maxlength="500" placeholder="https://example.com">
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.early_access.field_message') }}</label>
                                <textarea name="message" rows="6" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" required maxlength="5000">{{ old('message') }}</textarea>
                            </div>
                            <label class="md:col-span-2 flex gap-3 text-sm text-textSecondary">
                                <input type="checkbox" name="marketing_consent" value="1" @checked(old('marketing_consent')) class="mt-1 rounded border-border text-publicPrimary focus:ring-publicPrimary">
                                <span>{{ __('public.early_access.field_marketing_consent') }}</span>
                            </label>
                            <div class="md:col-span-2 flex flex-wrap gap-3">
                                <button type="submit" class="pl-public-primary-button">
                                    {{ $isDemoIntent ? __('public.early_access.submit_demo') : __('public.early_access.submit_early_access') }}
                                </button>
                                <a href="{{ route('login') }}" class="pl-public-secondary-button">{{ __('public.early_access.login') }}</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

@include('public.partials.footer')

</body>
</html>
