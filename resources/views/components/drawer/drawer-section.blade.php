@props([
    'section' => [],
    'title' => null,
    'description' => null,
    'items' => null,
])

@php
    $sectionTitle = $title ?? data_get($section, 'title');
    $sectionDescription = $description ?? data_get($section, 'description');
    $sectionItems = collect($items ?? data_get($section, 'items', []));
@endphp

<section {{ $attributes->class('border-b border-divider py-5 last:border-b-0 first:pt-0') }}>
    @if (filled($sectionTitle) || filled($sectionDescription))
        <div class="mb-4">
            @if (filled($sectionTitle))
                <h3 class="text-sm font-semibold text-textPrimary">{{ $sectionTitle }}</h3>
            @endif
            @if (filled($sectionDescription))
                <p class="mt-1 text-sm text-textSecondary">{{ $sectionDescription }}</p>
            @endif
        </div>
    @endif

    @if ($slot->isNotEmpty())
        {{ $slot }}
    @elseif ($sectionItems->isNotEmpty())
        <dl class="grid gap-3">
            @foreach ($sectionItems as $item)
                <div class="grid gap-1 sm:grid-cols-3 sm:gap-4">
                    <dt class="text-sm font-medium text-textSecondary">{{ $item['label'] ?? $item['key'] ?? 'Item' }}</dt>
                    <dd class="min-w-0 text-sm text-textPrimary sm:col-span-2">{{ $item['value'] ?? '' }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</section>
