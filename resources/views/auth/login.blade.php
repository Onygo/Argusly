@extends('layouts.auth', ['title' => __('public.auth.login_title'), 'fullBleed' => true])

@section('content')
    @php
        $marketingUrl = \App\Support\LocalizedMarketingUrl::route('landing');
        $registerUrl = route('register', ['plan' => 'creator']);
        $termsUrl = \App\Support\LocalizedMarketingUrl::route('public.legal.terms');
        $privacyUrl = \App\Support\LocalizedMarketingUrl::route('public.legal.privacy');
        $showForgotPassword = \Illuminate\Support\Facades\Route::has('password.request');
    @endphp

    <main>
        <section class="flex min-h-screen bg-white">
            <div class="hidden w-1/2 flex-col justify-between bg-gradient-to-br from-publicPrimary to-purple p-10 text-white lg:flex">
                <a href="{{ $marketingUrl }}" class="inline-flex items-center gap-3">
                    <x-brand-logo tone="inverse" text-class="pl-brand-logo-text text-[17px] text-white" />
                </a>

                <div>
                    <h1 class="max-w-xl text-4xl font-semibold leading-tight tracking-tight">
                        The operating system for<br>AI Visibility & Agentic Marketing.
                    </h1>
                    <p class="mt-6 max-w-md text-base leading-7 text-white/75">
                        Monitor how AI, search and competitors talk about your brand. Discover opportunities, automate actions, grow your presence.
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-4 text-sm text-white/70">
                        <span class="inline-flex items-center gap-1.5">
                            <i data-lucide="eye" class="h-4 w-4"></i>
                            AI Visibility
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <i data-lucide="mail" class="h-4 w-4"></i>
                            Brand Intelligence
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <i data-lucide="arrow-right" class="h-4 w-4"></i>
                            Agentic Marketing
                        </span>
                    </div>
                </div>

                <p class="text-sm text-white/45">Built for teams preparing for AI-first discovery.</p>
            </div>

            <div class="flex w-full flex-col justify-center px-6 py-12 sm:px-12 lg:w-1/2 lg:px-20">
                <div class="mx-auto w-full max-w-md">
                    <a href="{{ $marketingUrl }}" class="mb-10 inline-flex items-center gap-3 lg:hidden">
                        <x-brand-logo text-class="pl-brand-logo-text text-[17px] text-textPrimary" />
                    </a>

                    <div>
                        <h2 class="text-3xl font-semibold tracking-tight text-textPrimary">Welcome back</h2>
                        <p class="mt-2 text-base text-textMuted">Sign in to continue to your workspace.</p>
                    </div>

                    @if ($errors->any())
                        <div class="mt-6 rounded-lg border border-danger/30 bg-danger/10 p-3 text-sm text-danger">
                            <ul class="list-inside list-disc">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login.store') }}" class="mt-9 space-y-5">
                        @csrf

                        <label class="block">
                            <span class="text-sm font-semibold text-textPrimary">Email address</span>
                            <input
                                id="email"
                                type="email"
                                name="email"
                                value="{{ old('email') }}"
                                required
                                autofocus
                                autocomplete="email"
                                placeholder="you@company.com"
                                class="mt-2 w-full rounded-lg border border-border bg-white px-4 py-3 text-base text-textPrimary outline-none transition placeholder:text-textMuted focus:border-publicPrimary focus:ring-2 focus:ring-publicPrimary/10"
                            >
                        </label>

                        <label class="block">
                            <span class="text-sm font-semibold text-textPrimary">{{ __('public.auth.password') }}</span>
                            <input
                                id="password"
                                type="password"
                                name="password"
                                required
                                autocomplete="current-password"
                                placeholder="••••••••"
                                class="mt-2 w-full rounded-lg border border-border bg-white px-4 py-3 text-base text-textPrimary outline-none transition placeholder:text-textMuted focus:border-publicPrimary focus:ring-2 focus:ring-publicPrimary/10"
                            >
                        </label>

                        <div class="flex items-center justify-between gap-4 text-sm">
                            <label class="flex items-center gap-2 text-textMuted">
                                <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-border text-textPrimary focus:ring-publicPrimary/20">
                                <span>Remember me</span>
                            </label>
                            @if ($showForgotPassword)
                                <a href="{{ route('password.request') }}" class="font-medium text-publicPrimary hover:underline">Forgot password?</a>
                            @endif
                        </div>

                        <button class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-lg bg-textPrimary px-4 text-sm font-semibold text-white transition hover:bg-primaryHover focus:outline-none focus:ring-2 focus:ring-publicPrimary/20" type="submit">
                            {{ __('public.auth.sign_in') }}
                        </button>
                    </form>

                    @if ((bool) config('argusly.launch.public_registration_enabled', true))
                        <p class="mt-9 text-center text-sm text-textMuted">
                            Don't have an account?
                            <a href="{{ $registerUrl }}" class="font-semibold text-textPrimary hover:underline">Sign up</a>
                        </p>
                    @else
                        <p class="mt-9 text-center text-sm text-textMuted">
                            Need access?
                            <a href="{{ route('public.early-access.show') }}" class="font-semibold text-textPrimary hover:underline">Apply for the pilot</a>
                        </p>
                    @endif

                    <p class="mt-4 text-center text-sm text-textMuted">
                        Looking for Argusly?
                        <a href="{{ $marketingUrl }}" class="font-semibold text-textPrimary hover:underline">Back to the marketing site</a>
                    </p>

                    <p class="mt-12 text-center text-sm text-textMuted">
                        By continuing, you agree to our
                        <a href="{{ $termsUrl }}" class="underline hover:text-textPrimary">Terms</a>
                        and
                        <a href="{{ $privacyUrl }}" class="underline hover:text-textPrimary">Privacy Policy</a>.
                    </p>
                </div>
            </div>
        </section>
    </main>
@endsection
