@props(['items' => collect(), 'title' => 'Argusly Assistant', 'description' => 'What Argusly found, recommends, prepared, completed, and needs from you next.'])

<section {{ $attributes->merge(['class' => 'rounded-lg border border-border bg-background p-5']) }} aria-label="{{ $title }}">
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Argusly Assistant</p>
            <h2 class="mt-1 text-lg font-semibold text-textPrimary">{{ $title }}</h2>
            <p class="mt-1 max-w-3xl text-sm text-textSecondary">{{ $description }}</p>
        </div>
        <span class="inline-flex h-8 items-center rounded-full border border-border bg-surface px-3 text-xs font-medium text-textSecondary">
            {{ $items->count() }} active
        </span>
    </div>

    <div class="space-y-3">
        @forelse ($items as $item)
            <x-assistant.card :item="$item" />
        @empty
            <div class="rounded-md border border-dashed border-border bg-surface p-6 text-center">
                <p class="text-sm font-semibold text-textPrimary">No assistant messages yet</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-textSecondary">Argusly will add messages here as it discovers opportunities, prepares actions, completes work, or needs your input.</p>
            </div>
        @endforelse
    </div>
</section>
