@props([
    'code',
    'title',
    'description',
    'label',
    'note',
    'primaryHref' => route('marketing.home'),
    'primaryLabel' => 'Back to Argusly',
    'secondaryHref' => route('login'),
    'secondaryLabel' => 'Sign in',
])

<x-marketing.layout :title="$code.' - '.$title.' | Argusly'" :show-chrome="false">
    <section class="min-h-screen overflow-hidden bg-white">
        <div class="container-page flex min-h-screen flex-col">
            <header class="flex h-16 items-center justify-between">
                <x-brand :href="route('marketing.home')" />
                <a href="{{ route('marketing.home') }}" class="hidden text-sm font-semibold text-muted hover:text-ink sm:inline-flex">
                    Back to marketing
                </a>
            </header>

            <div class="flex flex-1 flex-col justify-center py-10 text-center sm:py-12">
                <div class="mx-auto max-w-4xl">
                    <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-line bg-white px-4 py-2 text-sm font-semibold text-muted shadow-sm">
                        <span class="size-2 rounded-full bg-blue"></span>
                        {{ $label }}
                    </div>

                    <p class="text-sm font-bold uppercase text-blue">{{ $code }}</p>
                    <h1 class="mx-auto mt-4 max-w-4xl text-5xl font-semibold leading-[0.95] tracking-tight text-ink sm:text-6xl">
                        {{ $title }}
                    </h1>
                    <p class="mx-auto mt-6 max-w-2xl text-base leading-7 text-muted sm:text-lg">
                        {{ $description }}
                    </p>

                    <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <x-ui.button :href="$primaryHref" variant="dark" size="lg" shape="pill">
                            {{ $primaryLabel }}
                            <x-app.icon name="arrow-right" class="size-4" />
                        </x-ui.button>
                        <x-ui.button :href="$secondaryHref" variant="light" size="lg" shape="pill">
                            {{ $secondaryLabel }}
                        </x-ui.button>
                    </div>

                    <p class="mx-auto mt-7 max-w-xl text-sm leading-6 text-muted">{{ $note }}</p>
                </div>

                <div class="mx-auto mt-9 w-full max-w-5xl rounded-2xl bg-gradient-to-br from-blue to-purple px-6 py-7 text-center text-white sm:px-10">
                    <h2 class="mx-auto max-w-2xl text-2xl font-semibold leading-tight tracking-tight sm:text-3xl">Join the Argusly pilot.</h2>
                    <p class="mx-auto mt-3 max-w-xl text-sm leading-6 text-white/75">Leave your details and we will follow up when your pilot workspace is ready.</p>
                    <div class="mt-5 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <x-ui.button href="{{ route('marketing.signup') }}" variant="light" shape="pill">
                            Request pilot access
                            <x-app.icon name="arrow-right" class="size-4" />
                        </x-ui.button>
                        <x-ui.button href="{{ route('marketing.home') }}" variant="glass" shape="pill">Back to homepage</x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-marketing.layout>
