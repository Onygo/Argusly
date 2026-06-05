@props([
    'items' => [],
    'groups' => [],
    'empty' => 'No details yet.',
])

@php
    $items = collect($items ?? [])->filter(fn ($item) => trim((string) $item) !== '')->values();
    $groups = collect($groups ?? [])
        ->map(function ($group) {
            $groupItems = collect($group['items'] ?? [])->filter(fn ($item) => trim((string) $item) !== '')->values()->all();

            return [
                'label' => trim((string) ($group['label'] ?? '')),
                'items' => $groupItems,
            ];
        })
        ->filter(fn ($group) => $group['label'] !== '' && $group['items'] !== [])
        ->values();
@endphp

@if ($groups->isNotEmpty())
    <div class="space-y-4">
        @foreach ($groups as $group)
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-textMuted">{{ $group['label'] }}</h3>
                <ul class="mt-2 space-y-2 text-sm leading-6 text-textSecondary">
                    @foreach ($group['items'] as $item)
                        <li class="flex gap-2">
                            <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary/60"></span>
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
@elseif ($items->isNotEmpty())
    <ul class="space-y-2 text-sm leading-6 text-textSecondary">
        @foreach ($items as $item)
            <li class="flex gap-2">
                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary/60"></span>
                <span>{{ $item }}</span>
            </li>
        @endforeach
    </ul>
@else
    <p class="text-sm leading-6 text-textMuted">{{ $empty }}</p>
@endif
