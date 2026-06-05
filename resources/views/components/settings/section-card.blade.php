@props([
    'title',
    'description' => null,
    'context' => null,
])

<section {{ $attributes->class(['rounded-lg border border-border bg-surface']) }}>
    <div class="border-b border-border px-5 py-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-textPrimary">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-1 text-sm text-textSecondary">{{ $description }}</p>
                @endif
            </div>
            @if ($context)
                <span class="inline-flex items-center rounded-md border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ $context }}</span>
            @endif
        </div>
    </div>

    <div class="p-5">
        {{ $slot }}
    </div>
</section>
