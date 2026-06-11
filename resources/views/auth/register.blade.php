@extends('layouts.auth', ['title' => 'Registreren'])

@section('content')
    <div class="flex flex-col items-center gap-3">
        <a href="{{ route('landing') }}" class="rounded-md px-2 py-1 hover:bg-surfaceMuted">
            <x-brand-logo size="lg" text-class="pl-brand-logo-text text-lg text-textPrimary" />
        </a>
        <p class="text-sm text-textSecondary">Vraag een account aan</p>
    </div>

    @if ($errors->any())
        <div class="rounded-md border border-danger/40 bg-danger/10 p-3 text-sm text-danger">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="space-y-4" method="POST" action="{{ route('register.store') }}">
        @csrf
        @php
            $selectedPlanSlug = old('plan', $selectedPlan?->slug ?? 'creator');
            $selectedPlanOnboarding = $selectedPlan?->onboardingData() ?? [];
            $selectedPlanOnboardingFeeCents = max(0, (int) ($selectedPlanOnboarding['fee_cents'] ?? 0));
            $selectedPlanOnboardingLabel = strtolower(trim((string) (($selectedPlanOnboarding['checkout_label'] ?? $selectedPlanOnboarding['label'] ?? ''))));
            $isOnboardingWaived = $onboardingFeeWaived ?? false;
            $formatEuro = function (int $amountCents): string {
                return 'EUR '.number_format($amountCents / 100, 0);
            };
        @endphp
        <div class="space-y-2">
            <label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70" for="plan">Plan</label>
            <select id="plan" name="plan" class="flex h-10 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary focus:outline-none focus:ring-2 focus:ring-primarySoftRing" required>
                @foreach(($plans ?? collect()) as $plan)
                    @php
                        $monthlyCents = (int) ($plan->price_monthly_cents ?? ($plan->monthly_price_cents > 0 ? $plan->monthly_price_cents : $plan->price_cents));
                        $planOnboarding = $plan->onboardingData();
                        $onboardingFeeCents = max(0, (int) ($planOnboarding['fee_cents'] ?? 0));
                        $onboardingLabel = strtolower(trim((string) (($planOnboarding['checkout_label'] ?? $planOnboarding['label'] ?? 'onboarding'))));
                        $optionLabel = $plan->name.' - '.number_format($monthlyCents / 100, 2).' '.$plan->currency.' / maand';
                        if (($planOnboarding['required'] ?? false) && $onboardingFeeCents > 0 && !$isOnboardingWaived) {
                            $optionLabel .= ' + '.number_format($onboardingFeeCents / 100, 2).' '.$plan->currency.' eenmalig '.$onboardingLabel;
                        }
                    @endphp
                    <option value="{{ $plan->slug }}" @selected($selectedPlanSlug === $plan->slug)>{{ $optionLabel }}</option>
                @endforeach
            </select>
            @if($isOnboardingWaived && $selectedPlanOnboardingFeeCents > 0)
                <p class="text-xs leading-5 text-publicPrimary">
                    {{ __('public.landing.pricing_register_onboarding_waived') }}
                </p>
            @elseif($selectedPlanOnboardingFeeCents > 0)
                <p class="text-xs leading-5 text-textSecondary">
                    {{ __('public.landing.pricing_register_onboarding', ['amount' => $formatEuro($selectedPlanOnboardingFeeCents), 'label' => $selectedPlanOnboardingLabel]) }}
                </p>
            @endif
            <p class="text-xs leading-5 text-textSecondary">
                {{ __('public.landing.pricing_register_credits_helper') }}
            </p>
        </div>
        <div class="space-y-2">
            <label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70" for="name">Naam</label>
            <input type="text" class="flex h-10 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm placeholder:text-textSecondary focus:outline-none focus:ring-2 focus:ring-primarySoftRing" id="name" name="name" required value="{{ old('name') }}">
        </div>
        <div class="space-y-2">
            <label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70" for="company_name">Bedrijfsnaam</label>
            <input type="text" class="flex h-10 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm placeholder:text-textSecondary focus:outline-none focus:ring-2 focus:ring-primarySoftRing" id="company_name" name="company_name" required value="{{ old('company_name') }}">
        </div>
        <div class="space-y-2">
            <label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70" for="email">E-mail</label>
            <input type="email" class="flex h-10 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm placeholder:text-textSecondary focus:outline-none focus:ring-2 focus:ring-primarySoftRing" id="email" name="email" placeholder="you@company.com" required value="{{ old('email') }}">
        </div>
        <div class="space-y-2">
            <label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70" for="password">Wachtwoord</label>
            <input type="password" class="flex h-10 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm placeholder:text-textSecondary focus:outline-none focus:ring-2 focus:ring-primarySoftRing" id="password" name="password" required>
        </div>
        <div class="space-y-2">
            <label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70" for="password_confirmation">Bevestig wachtwoord</label>
            <input type="password" class="flex h-10 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm placeholder:text-textSecondary focus:outline-none focus:ring-2 focus:ring-primarySoftRing" id="password_confirmation" name="password_confirmation" required>
        </div>
        <div class="hidden" aria-hidden="true">
            <label for="company_website">Website</label>
            <input type="text" id="company_website" name="company_website" value="" tabindex="-1" autocomplete="off">
        </div>
        @if((bool) config('services.turnstile.enabled', false) && filled(config('services.turnstile.site_key')))
            <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif
        <button class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse transition-colors hover:bg-primaryHover focus:outline-none focus:ring-2 focus:ring-primarySoftRing" type="submit">Aanvragen</button>
    </form>

    <p class="text-center text-sm text-textSecondary">
        Heb je al een account?
        <a class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium text-primary underline-offset-4 hover:underline h-auto p-0" href="{{ route('login') }}">Inloggen</a>
    </p>

    <p class="text-center text-sm">
        <a class="text-textSecondary hover:text-textPrimary underline-offset-4 hover:underline" href="{{ route('landing') }}">Terug naar publieke site</a>
    </p>
@endsection
