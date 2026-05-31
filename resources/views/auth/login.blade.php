<x-marketing.layout title="Sign in | Argusly">
    <section class="bg-panel py-16 sm:py-24">
        <div class="mx-auto grid max-w-5xl gap-8 px-4 sm:px-6 lg:grid-cols-[0.9fr_1.1fr] lg:px-8">
            <div class="self-center">
                <p class="eyebrow">Argusly app</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Sign in to your workspace</h1>
                <p class="mt-3 max-w-md text-sm leading-6 text-muted">Use one of the seeded demo users to explore tenant, brand and module access.</p>
                <div class="mt-6 rounded-lg border border-line bg-white p-4 text-sm text-muted">
                    <p class="font-semibold text-ink">Seeded demo login</p>
                    <p class="mt-2">alpha.owner@example.com</p>
                    <p>password</p>
                </div>
            </div>

            <div class="rounded-lg border border-line bg-white p-6 shadow-sm sm:p-8">
                <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
                    @csrf

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Email</span>
                        <input
                            type="email"
                            name="email"
                            value="{{ old('email', 'alpha.owner@example.com') }}"
                            required
                            autofocus
                            autocomplete="email"
                            class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink outline-none transition focus:border-blue focus:ring-2 focus:ring-blue/10"
                        >
                        @error('email')
                            <span class="mt-2 block text-sm text-red-600">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Password</span>
                        <input
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink outline-none transition focus:border-blue focus:ring-2 focus:ring-blue/10"
                        >
                        @error('password')
                            <span class="mt-2 block text-sm text-red-600">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="flex items-center gap-2 text-sm text-muted">
                        <input type="checkbox" name="remember" value="1" class="rounded border-line text-ink focus:ring-blue/20">
                        <span>Remember this browser</span>
                    </label>

                    <x-ui.button type="submit" class="w-full">Sign in</x-ui.button>
                </form>
            </div>
        </div>
    </section>
</x-marketing.layout>
