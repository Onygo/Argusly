<x-marketing.layout :title="$content['title'].' | Argusly'">
    <section class="relative overflow-hidden border-b border-line bg-white">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-80 argusly-grid opacity-35 [mask-image:linear-gradient(to_bottom,black,transparent)]"></div>
        <div class="container-page relative py-14 sm:py-20">
            <p class="eyebrow">{{ $content['eyebrow'] }}</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-semibold leading-tight tracking-tight text-ink sm:text-6xl">{{ $content['title'] }}</h1>
            <p class="mt-6 max-w-2xl text-base leading-7 text-muted">{{ $content['description'] }}</p>

            @if (! empty($content['hero_points']))
                <div class="mt-8 flex flex-wrap gap-2">
                    @foreach ($content['hero_points'] as $point)
                        <span class="rounded-full border border-line bg-white px-3 py-1.5 text-xs font-semibold text-muted shadow-sm shadow-slate-950/[0.02]">{{ $point }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="bg-panel py-12 sm:py-14">
        <div class="container-page grid gap-4 {{ in_array($page, ['privacy', 'terms'], true) ? 'max-w-4xl md:grid-cols-1' : 'md:grid-cols-3' }}">
            @foreach ($content['sections'] as $section)
                <article class="rounded-md border border-line bg-white p-6">
                    <h2 class="text-lg font-bold text-ink">{{ $section['title'] }}</h2>
                    <p class="mt-3 text-sm leading-6 text-muted">{{ $section['body'] }}</p>
                </article>
            @endforeach
        </div>
    </section>

    @if (! empty($content['details']) && ! in_array($page, ['privacy', 'terms'], true))
        <section class="border-y border-line bg-white py-16 sm:py-20">
            <div class="container-page">
                <div class="max-w-2xl">
                    <p class="eyebrow">{{ $content['details_eyebrow'] ?? 'Capabilities' }}</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $content['details_title'] }}</h2>
                    <p class="mt-4 text-sm leading-6 text-muted">{{ $content['details_description'] }}</p>
                </div>

                <div class="mt-10 grid gap-4 md:grid-cols-2">
                    @foreach ($content['details'] as $detail)
                        <article class="rounded-md border border-line bg-white p-6">
                            <div class="flex items-start gap-4">
                                <div class="grid size-10 shrink-0 place-items-center rounded-md border border-line bg-panel text-blue">
                                    <x-app.icon :name="$detail['icon']" class="size-5" />
                                </div>
                                <div>
                                    <h3 class="text-base font-semibold text-ink">{{ $detail['title'] }}</h3>
                                    <p class="mt-2 text-sm leading-6 text-muted">{{ $detail['body'] }}</p>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if (! empty($content['workflow']) && ! in_array($page, ['privacy', 'terms'], true))
        <section class="bg-panel py-16 sm:py-20">
            <div class="container-page grid gap-10 lg:grid-cols-[0.8fr_1.2fr] lg:items-start">
                <div>
                    <p class="eyebrow">{{ $content['workflow_eyebrow'] ?? 'How it works' }}</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $content['workflow_title'] }}</h2>
                    <p class="mt-4 text-sm leading-6 text-muted">{{ $content['workflow_description'] }}</p>
                    <x-ui.button href="{{ route('marketing.signup') }}" variant="dark" shape="pill" class="mt-7">
                        Request pilot
                        <x-app.icon name="arrow-right" class="size-4" />
                    </x-ui.button>
                </div>

                <div class="grid gap-3">
                    @foreach ($content['workflow'] as $index => $step)
                        <article class="rounded-md border border-line bg-white p-5">
                            <div class="flex gap-4">
                                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ink text-xs font-semibold text-white">{{ $index + 1 }}</span>
                                <div>
                                    <h3 class="text-sm font-semibold text-ink">{{ $step['title'] }}</h3>
                                    <p class="mt-2 text-sm leading-6 text-muted">{{ $step['body'] }}</p>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-marketing.layout>
