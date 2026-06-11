{{-- Hero --}}
<section class="pl-public-hero">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="max-w-3xl">
            <h1 class="pl-public-heading pl-public-heading-hero">
                {{ $heading }}
            </h1>
            <p class="mt-4 max-w-2xl text-pretty text-sm leading-6 text-textSecondary md:text-base">
                {{ $intro }}
            </p>
        </div>
    </div>
</section>

{{-- Content --}}
<section class="bg-background">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        @if (($pageKey ?? '') === 'legal.terms' && !empty($articles))
            <div class="space-y-4">
                @foreach($articles as $article)
                    <article class="pl-public-card p-6">
                        <h2 class="pl-public-heading pl-public-heading-h3">{{ $article['title'] }}</h2>
                        <ul class="mt-4 space-y-2 text-sm leading-6 text-textSecondary">
                            @foreach($article['points'] as $point)
                                <li>{{ $point }}</li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        @else
            <div class="grid gap-4 md:grid-cols-2">
                @foreach($sections as $section)
                    <article class="pl-public-card p-6">
                        <h2 class="pl-public-heading pl-public-heading-h3">{{ $section['title'] }}</h2>
                        @if(!empty($section['bullets']) && is_array($section['bullets']))
                            <ul class="mt-4 space-y-3 text-sm text-textSecondary">
                                @foreach($section['bullets'] as $bullet)
                                    <li class="flex items-start gap-3">
                                        <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                                        <span>{{ $bullet }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        @if (($pageKey ?? '') === 'company.contact')
            <div id="contact-form" class="mt-8 pl-public-card p-6">
                <h2 class="pl-public-heading pl-public-heading-h3">{{ __('public.page.contact_form.title') }}</h2>
                <p class="mt-2 text-sm text-textSecondary">{{ __('public.page.contact_form.subtitle') }}</p>

                @if (session('contact_status'))
                    <div class="mt-4 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-800">
                        {{ session('contact_status') }}
                    </div>
                @endif

                @php($formSummaryError = collect($errors->messages())->except(['recaptcha_token'])->flatten()->first())
                @if ($formSummaryError)
                    <div class="mt-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">
                        {{ $formSummaryError }}
                    </div>
                @endif

                <form method="POST" action="{{ \App\Support\LocalizedMarketingUrl::route('public.contact.submit') }}" class="mt-4 grid gap-3 md:grid-cols-2">
                    @csrf
                    <input type="hidden" name="topic" value="{{ old('topic', $topic ?? '') }}">
                    <input type="hidden" name="source_page" value="{{ old('source_page', $source ?? request()->path()) }}">
                    <input type="hidden" name="cta_label" value="{{ old('cta_label', $cta ?? '') }}">
                    <input type="hidden" name="url" value="{{ old('url', request()->fullUrl()) }}">

                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">{{ __('public.page.contact_form.name') }}</label>
                        <input type="text" name="name" value="{{ old('name') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required maxlength="120">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">{{ __('public.page.contact_form.email') }}</label>
                        <input type="email" name="email" value="{{ old('email') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required maxlength="190">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">{{ __('public.page.contact_form.company') }}</label>
                        <input type="text" name="company" value="{{ old('company') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="190">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">{{ __('public.page.contact_form.subject') }}</label>
                        <input type="text" name="subject" value="{{ old('subject', filled($subject ?? null) ? $subject : ($topic ?? '')) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="190">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs text-textSecondary">{{ __('public.page.contact_form.message') }}</label>
                        <textarea name="message" rows="6" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required maxlength="5000">{{ old('message') }}</textarea>
                    </div>
                    <x-forms.recaptcha action="contact" />
                    <div class="md:col-span-2 flex flex-wrap gap-3">
                        <button type="submit" class="pl-public-primary-button disabled:cursor-not-allowed disabled:opacity-60" @disabled(! $recaptchaConfigured)>{{ __('public.page.contact_form.send') }}</button>
                        <a href="mailto:{{ config('argusly.contact.recipient_email', config('mail.from.address')) }}" class="pl-public-secondary-button">{{ __('public.page.contact_form.direct_email') }}</a>
                        @if(!empty($scheduleCallUrl))
                            <a href="{{ $scheduleCallUrl }}" class="pl-public-secondary-button" target="_blank" rel="noopener">{{ __('public.page.contact_form.schedule_call') }}</a>
                        @endif
                    </div>
                </form>
            </div>
        @endif
    </div>
</section>

{{-- CTA --}}
<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mx-auto max-w-3xl text-center">
            <h2 class="pl-public-heading pl-public-heading-h2">
                {{ $ctaHeading ?? __('public.cta.title') }}
            </h2>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-textSecondary md:text-base">
                {{ $ctaText ?? __('public.cta.description') }}
            </p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ $primaryCtaHref }}" class="pl-public-primary-button">
                    {{ $primaryCtaLabel }}
                </a>
                <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.contact') }}" class="pl-public-secondary-button">
                    {{ $ctaSecondary ?? __('public.cta.secondary') }}
                </a>
            </div>
        </div>
    </div>
</section>
