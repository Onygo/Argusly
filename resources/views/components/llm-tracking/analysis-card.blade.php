@props([
    'title' => null,
    'description' => null,
    'icon' => null,
])

<section {{ $attributes->class('rounded-lg border border-border bg-surface p-5 shadow-sm') }}>
    @if ($title || $description)
        <div class="flex items-start gap-3">
            @if ($icon)
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-primarySoftBg text-primary">
                    <i data-lucide="{{ $icon }}" class="h-4 w-4"></i>
                </span>
            @endif
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-lg font-semibold text-textPrimary">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $description }}</p>
                @endif
            </div>
        </div>
    @endif

    <div @class(['mt-4' => $title || $description])>
        {{ $slot }}
    </div>
</section>
