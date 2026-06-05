@php
    /** @var \App\Models\StructuredAnswerBlock $block */
    $headingTag = in_array(($headingTag ?? 'h2'), ['h2', 'h3'], true) ? $headingTag : 'h2';
    $questionKey = strtolower(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $block->question))));
    $platforms = collect((array) ($block->platforms ?? []))
        ->map(fn ($item): string => trim((string) $item))
        ->filter()
        ->values();
    $entities = collect((array) ($block->entities ?? []))
        ->map(fn ($item): string => trim((string) $item))
        ->filter()
        ->values();
@endphp

<section
    data-answer-block="true"
    data-answer-question="{{ $questionKey }}"
    class="my-8 rounded-lg border border-border bg-surfaceSubtle px-4 py-4 text-textPrimary sm:px-5"
>
    <p class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-textSecondary">Quick answer</p>
    @if ($headingTag === 'h3')
        <h3 class="text-lg font-semibold leading-tight text-textPrimary">{{ $block->question }}</h3>
    @else
        <h2 class="text-lg font-semibold leading-tight text-textPrimary">{{ $block->question }}</h2>
    @endif
    <p class="mt-3 text-sm leading-7 text-textSecondary">{{ $block->answer }}</p>

    @if ($platforms->isNotEmpty() || $entities->isNotEmpty())
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($platforms as $platform)
                <span class="inline-flex items-center rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">
                    {{ $platform }}
                </span>
            @endforeach
            @foreach ($entities->take(3) as $entity)
                <span class="inline-flex items-center rounded-full border border-border/70 px-2.5 py-1 text-xs text-textSecondary/90">
                    {{ $entity }}
                </span>
            @endforeach
        </div>
    @endif
</section>
