@props([
    'title' => null,
    'subtitle' => null,
    'description' => null,
    'titleId' => null,
    'descriptionId' => null,
    'keyboardEscape' => [],
    'focusReturnTarget' => null,
])

<header {{ $attributes->class('flex shrink-0 items-start justify-between gap-4 border-b border-divider p-5') }}>
    <div class="min-w-0">
        @if (filled($subtitle))
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ $subtitle }}</p>
        @endif

        @if (filled($title))
            <h2 id="{{ $titleId }}" class="mt-1 text-base font-semibold text-textPrimary">{{ $title }}</h2>
        @endif

        @if (filled($description))
            <p id="{{ $descriptionId }}" class="mt-1 text-sm text-textSecondary">{{ $description }}</p>
        @endif
    </div>

    <div class="flex shrink-0 items-center gap-2">
        @isset($actions)
            {{ $actions }}
        @endif

        @isset($close)
            {{ $close }}
        @else
            <button
                type="button"
                class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border bg-surface text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary focus:outline-none focus:ring-2 focus:ring-primarySoftRing"
                aria-label="Close drawer"
                data-drawer-close
                data-focus-return-target="{{ $focusReturnTarget }}"
                data-escape-enabled="{{ data_get($keyboardEscape, 'enabled', true) ? 'true' : 'false' }}"
            >
                <span aria-hidden="true">&times;</span>
            </button>
        @endif
    </div>
</header>
