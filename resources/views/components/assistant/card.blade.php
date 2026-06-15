@props(['item'])

@php
    $priorityTone = match ((string) $item->priority_label) {
        'critical' => 'border-rose-200 bg-rose-50 text-rose-800',
        'high' => 'border-amber-200 bg-amber-50 text-amber-800',
        'low' => 'border-slate-200 bg-slate-50 text-slate-700',
        default => 'border-sky-200 bg-sky-50 text-sky-800',
    };
    $sections = collect($item->messageSections())->filter(fn ($value) => trim((string) $value) !== '');
    $sectionGridClass = match (true) {
        $sections->count() >= 3 => 'md:grid-cols-3',
        $sections->count() === 2 => 'md:grid-cols-2',
        default => 'md:grid-cols-1',
    };
@endphp

<article {{ $attributes->merge(['class' => 'rounded-lg border border-border bg-surface p-5']) }}>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold {{ $priorityTone }}">{{ ucfirst((string) $item->priority_label) }}</span>
                <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', ucfirst((string) $item->category)) }}</span>
                <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', ucfirst((string) $item->assistant_state)) }}</span>
            </div>
            <h3 class="mt-3 text-base font-semibold text-textPrimary">{{ $item->title }}</h3>
            @if ($item->summary)
                <p class="mt-1 text-sm text-textSecondary">{{ $item->summary }}</p>
            @endif
        </div>
        @if ($item->primary_cta_url)
            <a href="{{ $item->primary_cta_url }}" class="inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                <i data-lucide="arrow-right" class="h-4 w-4"></i>
                {{ $item->primary_cta_label ?: 'Open' }}
            </a>
        @endif
    </div>

    <div class="mt-4 grid gap-3 {{ $sectionGridClass }}">
        @foreach ($sections as $label => $message)
            <div class="rounded-md border border-border bg-background p-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ $label }}</p>
                <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $message }}</p>
            </div>
        @endforeach
    </div>
</article>
