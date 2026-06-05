@props([
    'title' => null,
    'eyebrow' => null,
    'description' => null,
    'icon' => null,
    'actionLabel' => null,
    'actionHref' => null,
])

<section {{ $attributes->class('rounded-lg border border-border bg-surface p-5') }}>
    @if ($title || $eyebrow || $description || isset($actions))
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                @if ($eyebrow)
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-textMuted">{{ $eyebrow }}</p>
                @endif
                @if ($title)
                    <div class="mt-1 flex items-center gap-2">
                        @if ($icon)
                            <i data-lucide="{{ $icon }}" class="h-4 w-4 text-textMuted" aria-hidden="true"></i>
                        @endif
                        <h2 class="text-lg font-semibold text-textPrimary">{{ $title }}</h2>
                    </div>
                @endif
                @if ($description)
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-textSecondary">{{ $description }}</p>
                @endif
            </div>

            @if (isset($actions))
                <div class="flex flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @elseif ($actionLabel && $actionHref)
                <a href="{{ $actionHref }}" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                    {{ $actionLabel }}
                </a>
            @endif
        </div>
    @endif

    <div @class(['mt-4' => $title || $eyebrow || $description || isset($actions)])>
        {{ $slot }}
    </div>
</section>
