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
    $recaptchaConfigured = app(\App\Services\Security\RecaptchaService::class)->isConfigured();
@endphp

{{-- Hero --}}
<section class="pl-public-hero">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="max-w-3xl">
            <h1 class="pl-public-heading pl-public-heading-hero">
                {{ __('public.contact.hero_title') }}
            </h1>
            <p class="mt-4 max-w-2xl text-pretty text-sm leading-6 text-textSecondary md:text-base">
                {{ __('public.contact.hero_text') }}
            </p>
        </div>
    </div>
</section>

{{-- Contact Options Cards --}}
<section class="bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-4 md:grid-cols-3">
            @foreach(__('public.contact.options') as $option)
                <div class="pl-public-card-compact p-5">
                    <x-public.icon :name="$option['icon']" size="sm" />
                    <h3 class="mt-4 text-sm font-semibold text-textPrimary">{{ $option['title'] }}</h3>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $option['text'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- Contact Form --}}
<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-8 lg:grid-cols-[1.2fr_0.8fr] lg:items-start">
            {{-- Form Card --}}
            <div id="contact-form" class="pl-public-card p-6 md:p-8">
                <h2 class="text-xl font-semibold text-textPrimary">{{ __('public.page.contact_form.title') }}</h2>
                <p class="mt-2 text-sm text-textSecondary">{{ __('public.page.contact_form.subtitle') }}</p>

                @if (session('contact_status'))
                    <div class="mt-4 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800">
                        {{ session('contact_status') }}
                    </div>
                @endif

                @php($formSummaryError = collect($errors->messages())->except(['recaptcha_token'])->flatten()->first())
                @if ($formSummaryError)
                    <div class="mt-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">
                        {{ $formSummaryError }}
                    </div>
                @endif

                <form method="POST" action="{{ LocalizedMarketingUrl::route('public.contact.submit') }}" class="mt-6 space-y-4">
                    @csrf
                    <input type="hidden" name="topic" value="{{ old('topic', $topic ?? '') }}">
                    <input type="hidden" name="source_page" value="{{ old('source_page', $source ?? request()->path()) }}">
                    <input type="hidden" name="cta_label" value="{{ old('cta_label', $cta ?? '') }}">
                    <input type="hidden" name="url" value="{{ old('url', request()->fullUrl()) }}">

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.page.contact_form.name') }}</label>
                            <input type="text" name="name" value="{{ old('name') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" required maxlength="120">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.page.contact_form.email') }}</label>
                            <input type="email" name="email" value="{{ old('email') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" required maxlength="190">
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.page.contact_form.company') }}</label>
                            <input type="text" name="company" value="{{ old('company') }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" maxlength="190">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.contact.request_type_label') }}</label>
                            <select name="request_type" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary">
                                @foreach(__('public.contact.request_types') as $value => $label)
                                    <option value="{{ $value }}" @selected(old('request_type', $topic ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.page.contact_form.subject') }}</label>
                        <input type="text" name="subject" value="{{ old('subject', filled($subject ?? null) ? $subject : ($topic ?? '')) }}" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" maxlength="190">
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.page.contact_form.message') }}</label>
                        <textarea name="message" rows="5" class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary" required maxlength="5000">{{ old('message') }}</textarea>
                    </div>

                    <x-forms.recaptcha action="contact" />

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="pl-public-primary-button disabled:cursor-not-allowed disabled:opacity-60" @disabled(! $recaptchaConfigured)>
                            {{ __('public.page.contact_form.send') }}
                        </button>
                        <a href="mailto:{{ config('argusly.contact.recipient_email', config('mail.from.address')) }}" class="pl-public-secondary-button">
                            {{ __('public.page.contact_form.direct_email') }}
                        </a>
                    </div>
                </form>
            </div>

            {{-- Trust / Reassurance Block --}}
            <div class="space-y-4">
                <div class="pl-public-card p-6">
                    <h3 class="pl-public-heading pl-public-heading-h3">{{ __('public.contact.trust_title') }}</h3>
                    <ul class="mt-4 space-y-3">
                        @foreach(__('public.contact.trust_points') as $point)
                            <li class="flex items-start gap-3 text-sm text-textSecondary">
                                <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                                <span>{{ $point }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if(!empty($scheduleCallUrl))
                    <div class="pl-public-cta-panel pl-public-cta-panel--split p-6">
                        <h3 class="text-lg font-semibold">{{ __('public.contact.schedule_title') }}</h3>
                        <p class="mt-2 text-sm text-white/80">{{ __('public.contact.schedule_text') }}</p>
                        <a href="{{ $scheduleCallUrl }}" class="pl-public-cta-primary mt-4" target="_blank" rel="noopener">
                            {{ __('public.page.contact_form.schedule_call') }}
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
