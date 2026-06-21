@props([
    'pageType' => null,
    'pageSlug' => null,
    'locale' => null,
    'items' => null,
    'schema' => null,
    'heading' => null,
    'intro' => null,
    'eyebrow' => 'FAQ',
    'emitSchema' => true,
])

@php
    $resolvedLocale = strtolower((string) ($locale ?: app()->getLocale()));
    $faqPayload = null;

    if ($items === null && $pageType !== null && $pageSlug !== null) {
        $faqPayload = app(\App\Services\Faq\FaqIntelligenceRenderer::class)
            ->forPage((string) $pageType, (string) $pageSlug, $resolvedLocale);

        $items = $faqPayload['items'];
        $schema = $schema ?: $faqPayload['schema'];
    }

    $items = collect($items ?? []);
    $sectionHeading = $heading ?: ($resolvedLocale === 'nl' ? 'Veelgestelde vragen' : 'Frequently asked questions');
@endphp

@if ($items->isNotEmpty())
    @if ($emitSchema && ! empty($schema))
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif

    <section id="faq" class="bg-white">
        <div class="mx-auto max-w-4xl px-4 py-16 sm:px-6 md:py-20">
            <div class="text-center">
                @if ($eyebrow !== '')
                    <p class="pl-public-eyebrow">{{ $eyebrow }}</p>
                @endif
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $sectionHeading }}</h2>
                @if ($intro)
                    <p class="mx-auto mt-4 max-w-2xl text-sm leading-7 text-textSecondary">{{ $intro }}</p>
                @endif
            </div>

            <div class="mt-10 space-y-4">
                @foreach ($items as $item)
                    <details class="group pl-public-card-compact">
                        <summary class="flex cursor-pointer items-center justify-between gap-4 px-6 py-5 text-left text-sm font-medium text-textPrimary">
                            <span>{{ $item->question }}</span>
                            <x-public.icon name="chevron-down" size="xs" class="transition-transform group-open:rotate-180" />
                        </summary>
                        <div class="px-6 pb-5 text-sm leading-7 text-textSecondary">
                            {{ $item->answer }}
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>
@endif
