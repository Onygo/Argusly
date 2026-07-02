@props([
    'items' => [],
])

@php
    $items = collect($items)
        ->map(fn ($item) => is_string($item) ? ['label' => $item, 'url' => null] : $item)
        ->filter(fn ($item) => filled($item['label'] ?? null))
        ->values();
@endphp

@if ($items->isNotEmpty())
    <nav {{ $attributes->class('pl-breadcrumb') }} aria-label="Breadcrumb">
        <ol class="pl-breadcrumb__list">
            @foreach ($items as $item)
                @php($isLast = $loop->last)
                <li class="pl-breadcrumb__item">
                    @if (! $isLast && filled($item['url'] ?? null))
                        <a href="{{ $item['url'] }}" class="pl-breadcrumb__link">{{ $item['label'] }}</a>
                    @else
                        <span class="pl-breadcrumb__current" @if($isLast) aria-current="page" @endif>{{ $item['label'] }}</span>
                    @endif
                    @unless ($isLast)
                        <i data-lucide="chevron-right" class="pl-breadcrumb__separator"></i>
                    @endunless
                </li>
            @endforeach
        </ol>
    </nav>
@endif
