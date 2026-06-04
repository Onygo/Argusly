<x-marketing.layout title="Reset password | Argusly" :show-chrome="false">
    <section class="flex min-h-screen bg-white">
        <div class="hidden w-1/2 flex-col justify-between bg-gradient-to-br from-blue to-purple p-10 text-white lg:flex">
            <x-brand tone="light" :href="route('marketing.home')" />

            <div>
                <h1 class="max-w-xl text-4xl font-semibold leading-tight tracking-tight">
                    Get back to your workspace.
                </h1>
                <p class="mt-6 max-w-md text-base leading-7 text-white/75">
                    We will send a secure reset link to the email address connected to your Argusly account.
                </p>
            </div>

            <p class="text-sm text-white/45">Password reset links expire after 60 minutes.</p>
        </div>

        <div class="flex w-full flex-col justify-center px-6 py-12 sm:px-12 lg:w-1/2 lg:px-20">
            <div class="mx-auto w-full max-w-md">
                <x-brand class="mb-10 lg:hidden" :href="route('marketing.home')" />

                <div>
                    <h2 class="text-3xl font-semibold tracking-tight text-ink">Reset your password</h2>
                    <p class="mt-2 text-base text-muted">Enter your email and we will send you a reset link.</p>
                </div>

                @if (session('status'))
                    <div class="mt-7 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.email') }}" class="mt-9 space-y-5">
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

                    <x-ui.button type="submit" variant="dark" shape="lg" class="w-full">Send reset link</x-ui.button>
                </form>

                <p class="mt-9 text-center text-sm text-muted">
                    Remembered it? <a href="{{ route('login') }}" class="font-semibold text-ink hover:underline">Back to sign in</a>
                </p>
            </div>
        </div>
    </section>
</x-marketing.layout>
