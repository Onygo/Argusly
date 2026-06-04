<x-marketing.layout title="Choose new password | Argusly" :show-chrome="false">
    <section class="flex min-h-screen bg-white">
        <div class="hidden w-1/2 flex-col justify-between bg-gradient-to-br from-blue to-purple p-10 text-white lg:flex">
            <x-brand tone="light" :href="route('marketing.home')" />

            <div>
                <h1 class="max-w-xl text-4xl font-semibold leading-tight tracking-tight">
                    Set a new password.
                </h1>
                <p class="mt-6 max-w-md text-base leading-7 text-white/75">
                    Use a strong password to keep your Argusly workspace and connected marketing systems protected.
                </p>
            </div>

            <p class="text-sm text-white/45">You can update this again from your profile settings.</p>
        </div>

        <div class="flex w-full flex-col justify-center px-6 py-12 sm:px-12 lg:w-1/2 lg:px-20">
            <div class="mx-auto w-full max-w-md">
                <x-brand class="mb-10 lg:hidden" :href="route('marketing.home')" />

                <div>
                    <h2 class="text-3xl font-semibold tracking-tight text-ink">Choose a new password</h2>
                    <p class="mt-2 text-base text-muted">Enter the email address for your account and your new password.</p>
                </div>

                <form method="POST" action="{{ route('password.update') }}" class="mt-9 space-y-5">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">

                    <label class="block">
                        <span class="text-sm font-semibold text-ink">Email address</span>
                        <input
                            type="email"
                            name="email"
                            value="{{ old('email', $request->email) }}"
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
                        <span class="text-sm font-semibold text-ink">New password</span>
                        <input
                            type="password"
                            name="password"
                            required
                            autocomplete="new-password"
                            placeholder="********"
                            class="mt-2 w-full rounded-lg border border-line bg-white px-4 py-3 text-base text-ink outline-none transition placeholder:text-muted focus:border-blue focus:ring-2 focus:ring-blue/10"
                        >
                        @error('password')
                            <span class="mt-2 block text-sm text-red-600">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-ink">Confirm password</span>
                        <input
                            type="password"
                            name="password_confirmation"
                            required
                            autocomplete="new-password"
                            placeholder="********"
                            class="mt-2 w-full rounded-lg border border-line bg-white px-4 py-3 text-base text-ink outline-none transition placeholder:text-muted focus:border-blue focus:ring-2 focus:ring-blue/10"
                        >
                    </label>

                    <x-ui.button type="submit" variant="dark" shape="lg" class="w-full">Update password</x-ui.button>
                </form>

                <p class="mt-9 text-center text-sm text-muted">
                    Need a new link? <a href="{{ route('password.request') }}" class="font-semibold text-ink hover:underline">Request another reset</a>
                </p>
            </div>
        </div>
    </section>
</x-marketing.layout>
