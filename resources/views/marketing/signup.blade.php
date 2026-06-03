<x-marketing.layout title="Sign up | Argusly">
    <section class="relative overflow-hidden border-b border-line bg-white">
        <div class="pointer-events-none absolute inset-0 argusly-grid opacity-40 [mask-image:radial-gradient(ellipse_at_top,black,transparent_70%)]"></div>
        <div class="container-page relative grid gap-12 py-16 lg:grid-cols-[0.9fr_1.1fr] lg:items-start lg:py-24">
            <div>
                <p class="eyebrow">Pilot subscription</p>
                <h1 class="mt-4 max-w-2xl text-5xl font-semibold leading-[0.95] tracking-tight text-ink sm:text-6xl">
                    Request an Argusly pilot.
                </h1>
                <p class="mt-6 max-w-xl text-base leading-7 text-muted sm:text-lg">
                    Leave your details and we will follow up when your pilot workspace is ready. We will use your request to prepare the right setup for your team.
                </p>

                <div class="mt-10 grid gap-3 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
                    @foreach ([['1', 'Share your brand'], ['2', 'We prepare your workspace'], ['3', 'Start monitoring']] as [$step, $label])
                        <div class="rounded-md border border-line bg-white p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Step {{ $step }}</p>
                            <p class="mt-2 text-sm font-semibold text-ink">{{ $label }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <x-ui.card class="p-6 sm:p-8">
                @if (session('status'))
                    <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <p class="font-semibold">Could not submit signup</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('marketing.signup.store') }}" class="space-y-5">
                    @csrf

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-semibold text-ink">Name</span>
                            <input name="name" value="{{ old('name') }}" required autocomplete="name" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2.5 text-sm text-ink outline-none transition focus:border-blue focus:ring-2 focus:ring-blue/10">
                        </label>

                        <label class="block">
                            <span class="text-sm font-semibold text-ink">Work email</span>
                            <input name="email" type="email" value="{{ old('email') }}" required autocomplete="email" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2.5 text-sm text-ink outline-none transition focus:border-blue focus:ring-2 focus:ring-blue/10">
                        </label>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-semibold text-ink">Company</span>
                            <input name="company" value="{{ old('company') }}" required autocomplete="organization" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2.5 text-sm text-ink outline-none transition focus:border-blue focus:ring-2 focus:ring-blue/10">
                        </label>

                        <label class="block">
                            <span class="text-sm font-semibold text-ink">Website</span>
                            <input name="website" type="url" value="{{ old('website') }}" placeholder="https://example.com" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2.5 text-sm text-ink outline-none transition placeholder:text-muted focus:border-blue focus:ring-2 focus:ring-blue/10">
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-sm font-semibold text-ink">Role</span>
                        <input name="role" value="{{ old('role') }}" placeholder="Founder, CMO, SEO lead..." class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2.5 text-sm text-ink outline-none transition placeholder:text-muted focus:border-blue focus:ring-2 focus:ring-blue/10">
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-ink">What do you want Argusly to help with?</span>
                        <textarea name="goal" rows="5" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2.5 text-sm leading-6 text-ink outline-none transition focus:border-blue focus:ring-2 focus:ring-blue/10">{{ old('goal') }}</textarea>
                    </label>

                    <label class="flex items-start gap-3 rounded-md border border-line bg-panel/60 p-4 text-sm leading-6 text-muted">
                        <input type="checkbox" name="consent" value="1" required class="mt-1 size-4 rounded border-line text-blue focus:ring-blue/20">
                        <span>
                            I agree that Argusly may contact me about the pilot subscription and I accept the
                            <a href="{{ route('marketing.page', 'privacy') }}" class="font-semibold text-ink underline">Privacy Policy</a>
                            and
                            <a href="{{ route('marketing.page', 'terms') }}" class="font-semibold text-ink underline">Terms & Conditions</a>.
                        </span>
                    </label>

                    <x-ui.button type="submit" variant="dark" shape="lg" class="w-full">Request pilot subscription</x-ui.button>
                </form>

                <p class="mt-6 text-center text-sm text-muted">Already have an account? <a href="{{ route('login') }}" class="font-semibold text-ink hover:underline">Sign in</a></p>
            </x-ui.card>
        </div>
    </section>
</x-marketing.layout>
