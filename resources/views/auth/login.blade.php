<x-marketing.layout title="Sign in | Argusly" :show-chrome="false">
    <section class="flex min-h-screen bg-white">
        <div class="hidden w-1/2 flex-col justify-between bg-gradient-to-br from-blue to-purple p-10 text-white lg:flex">
            <x-brand tone="light" :href="route('marketing.home')" />

            <div>
                <h1 class="max-w-xl text-4xl font-semibold leading-tight tracking-tight">
                    The operating system for<br>AI Visibility & Agentic Marketing.
                </h1>
                <p class="mt-6 max-w-md text-base leading-7 text-white/75">
                    Monitor how AI, search and competitors talk about your brand. Discover opportunities, automate actions, grow your presence.
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-4 text-sm text-white/70">
                    <span class="inline-flex items-center gap-1.5"><x-app.icon name="eye" class="size-4" /> AI Visibility</span>
                    <span class="inline-flex items-center gap-1.5"><x-app.icon name="mail" class="size-4" /> Brand Intelligence</span>
                    <span class="inline-flex items-center gap-1.5"><x-app.icon name="arrow-right" class="size-4" /> Agentic Marketing</span>
                </div>
            </div>

            <p class="text-sm text-white/45">Built for teams preparing for AI-first discovery.</p>
        </div>

        <div class="flex w-full flex-col justify-center px-6 py-12 sm:px-12 lg:w-1/2 lg:px-20">
            <div class="mx-auto w-full max-w-md">
                <x-brand class="mb-10 lg:hidden" :href="route('marketing.home')" />

                <div>
                    <h2 class="text-3xl font-semibold tracking-tight text-ink">Welcome back</h2>
                    <p class="mt-2 text-base text-muted">Sign in to continue to your workspace.</p>
                </div>

                <form method="POST" action="{{ route('login.store') }}" class="mt-9 space-y-5">
                    @csrf

                    <label class="block">
                        <span class="text-sm font-semibold text-ink">Email address</span>
                        <input
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            required
                            autofocus
                            autocomplete="email"
                            placeholder="you@company.com"
                            class="mt-2 w-full rounded-lg border border-line bg-white px-4 py-3 text-base text-ink outline-none transition placeholder:text-muted focus:border-blue focus:ring-2 focus:ring-blue/10"
                        >
                        @error('email')
                            <span class="mt-2 block text-sm text-red-600">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-ink">Password</span>
                        <input
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                            class="mt-2 w-full rounded-lg border border-line bg-white px-4 py-3 text-base text-ink outline-none transition placeholder:text-muted focus:border-blue focus:ring-2 focus:ring-blue/10"
                        >
                        @error('password')
                            <span class="mt-2 block text-sm text-red-600">{{ $message }}</span>
                        @enderror
                    </label>

                    <div class="flex items-center justify-between text-sm">
                        <label class="flex items-center gap-2 text-muted">
                            <input type="checkbox" name="remember" value="1" class="size-4 rounded border-line text-ink focus:ring-blue/20">
                            <span>Remember me</span>
                        </label>
                        <a href="#" class="font-medium text-blue hover:underline">Forgot password?</a>
                    </div>

                    <x-ui.button type="submit" variant="dark" shape="lg" class="w-full">Sign in</x-ui.button>
                </form>

                <p class="mt-9 text-center text-sm text-muted">Don't have an account? <a href="{{ route('marketing.signup') }}" class="font-semibold text-ink hover:underline">Sign up</a></p>
                <p class="mt-4 text-center text-sm text-muted">Looking for Argusly? <a href="{{ route('marketing.home') }}" class="font-semibold text-ink hover:underline">Back to the marketing site</a></p>
                <p class="mt-12 text-center text-sm text-muted">By continuing, you agree to our <a href="{{ route('marketing.page', 'terms') }}" class="underline hover:text-ink">Terms</a> and <a href="{{ route('marketing.page', 'privacy') }}" class="underline hover:text-ink">Privacy Policy</a>.</p>
            </div>
        </div>
    </section>
</x-marketing.layout>
