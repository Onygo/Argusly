<x-marketing.layout title="Contact | Argusly">
    @php
        $topics = [
            'pilot' => 'Pilot request',
            'sales' => 'Sales conversation',
            'support' => 'Customer support',
            'partnership' => 'Partnership',
            'press' => 'Press',
            'other' => 'Other',
        ];
    @endphp

    <section class="relative overflow-hidden border-b border-line bg-white">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-80 argusly-grid opacity-35 [mask-image:linear-gradient(to_bottom,black,transparent)]"></div>
        <div class="container-page relative grid gap-12 py-14 lg:grid-cols-[0.88fr_1.12fr] lg:items-start lg:py-20">
            <div class="lg:sticky lg:top-24">
                <p class="eyebrow">Contact</p>
                <h1 class="mt-4 max-w-2xl text-5xl font-semibold leading-[0.95] tracking-tight text-ink sm:text-6xl">
                    Talk to Argusly.
                </h1>
                <p class="mt-6 max-w-xl text-base leading-7 text-muted sm:text-lg">
                    Tell us what you are trying to solve across AI visibility, brand intelligence or marketing operations. We will route your message to the right person.
                </p>

                <div class="mt-10 hidden gap-3 lg:grid lg:grid-cols-1 xl:grid-cols-3">
                    @foreach ([
                        ['Pilot', 'Workspace intake and early access review.'],
                        ['Support', 'Questions from active customer teams.'],
                        ['Partner', 'Integrations, agencies and channel ideas.'],
                    ] as [$title, $body])
                        <div class="rounded-md border border-line bg-white p-4 shadow-sm shadow-slate-950/[0.02]">
                            <p class="text-sm font-semibold text-ink">{{ $title }}</p>
                            <p class="mt-2 text-xs leading-5 text-muted">{{ $body }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-8 hidden rounded-md border border-line bg-panel/70 p-5 lg:block">
                    <p class="text-sm font-semibold text-ink">Prefer pilot intake?</p>
                    <p class="mt-2 text-sm leading-6 text-muted">For a structured access request, use the dedicated pilot form.</p>
                    <x-ui.button href="{{ route('marketing.signup') }}" variant="secondary" size="sm" class="mt-4">Request pilot access</x-ui.button>
                </div>
            </div>

            <x-ui.card class="p-6 shadow-sm shadow-slate-950/[0.03] sm:p-8">
                @if (session('status'))
                    <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <p class="font-semibold">Could not send message</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('marketing.contact.store') }}" class="space-y-5">
                    @csrf
                    <label class="sr-only" aria-hidden="true" tabindex="-1">
                        Homepage
                        <input name="homepage" value="" autocomplete="off" tabindex="-1">
                    </label>

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
                            <input name="company" value="{{ old('company') }}" autocomplete="organization" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2.5 text-sm text-ink outline-none transition focus:border-blue focus:ring-2 focus:ring-blue/10">
                        </label>

                        <label class="block">
                            <span class="text-sm font-semibold text-ink">Website</span>
                            <input name="website" type="url" value="{{ old('website') }}" placeholder="https://example.com" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2.5 text-sm text-ink outline-none transition placeholder:text-muted focus:border-blue focus:ring-2 focus:ring-blue/10">
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-sm font-semibold text-ink">Topic</span>
                        <select name="topic" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2.5 text-sm text-ink outline-none transition focus:border-blue focus:ring-2 focus:ring-blue/10">
                            @foreach ($topics as $value => $label)
                                <option value="{{ $value }}" @selected(old('topic', request('topic', 'pilot')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-ink">Message</span>
                        <textarea name="message" rows="6" required placeholder="What should we know before we reply?" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2.5 text-sm leading-6 text-ink outline-none transition placeholder:text-muted focus:border-blue focus:ring-2 focus:ring-blue/10">{{ old('message') }}</textarea>
                    </label>

                    <label class="flex items-start gap-3 rounded-md border border-line bg-panel/60 p-4 text-sm leading-6 text-muted">
                        <input type="checkbox" name="consent" value="1" required class="mt-1 size-4 rounded border-line text-blue focus:ring-blue/20">
                        <span>
                            I agree that Argusly may contact me about this request and I accept the
                            <a href="{{ route('marketing.page', 'privacy') }}" class="font-semibold text-ink underline">Privacy Policy</a>
                            and
                            <a href="{{ route('marketing.page', 'terms') }}" class="font-semibold text-ink underline">Terms & Conditions</a>.
                        </span>
                    </label>

                    <x-ui.button type="submit" variant="dark" shape="lg" class="w-full">
                        Send message
                        <x-app.icon name="arrow-right" class="size-4" />
                    </x-ui.button>
                </form>

                <div class="mt-6 rounded-md border border-line bg-panel/70 p-4 lg:hidden">
                    <p class="text-sm font-semibold text-ink">Prefer pilot intake?</p>
                    <p class="mt-2 text-sm leading-6 text-muted">Use the dedicated pilot form for structured early access requests.</p>
                    <x-ui.button href="{{ route('marketing.signup') }}" variant="secondary" size="sm" class="mt-4">Request pilot access</x-ui.button>
                </div>
            </x-ui.card>
        </div>
    </section>
</x-marketing.layout>
