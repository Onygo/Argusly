<x-dashboard.section :title="$title" :description="$items->count().' matching '.str($title)->lower()">
    @if ($items->isEmpty())
        <p class="text-sm text-muted">{{ $empty }}</p>
    @else
        <div class="divide-y divide-line rounded-md border border-line bg-white">
            @foreach ($items as $item)
                <a href="{{ $route($item) }}" class="block px-4 py-4 transition hover:bg-panel">
                    <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-ink">{{ $label($item) }}</p>
                            @if ($description($item))
                                <p class="mt-1 line-clamp-2 text-sm leading-6 text-muted">{{ $description($item) }}</p>
                            @endif
                        </div>
                        <x-ui.badge>{{ $meta($item) }}</x-ui.badge>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-dashboard.section>
