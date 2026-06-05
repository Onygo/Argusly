@props([
    'content' => null,
    'showRemote' => true,
    'showTooltips' => true,
    'size' => 'sm',
])

@php
    use App\View\Presenters\ContentStatusPresenter;

    $presenter = $content ? ContentStatusPresenter::for($content) : null;
    $primary = $presenter?->primaryBadge();
    $secondary = $showRemote ? $presenter?->secondaryBadge() : null;
@endphp

@if($presenter)
    <div {{ $attributes->class(['inline-flex items-center gap-1.5']) }}>
        {{-- PublishLayer lifecycle status --}}
        <x-status-badge
            :label="$primary['label']"
            :color="$primary['color']"
            :icon="$primary['icon']"
            :size="$size"
        />

        {{-- Remote delivery status (when relevant) --}}
        @if($secondary)
            <x-status-badge
                :label="$secondary['label']"
                :color="$secondary['color']"
                :icon="$secondary['icon']"
                :size="$size"
                :tooltip="$showTooltips ? ($secondary['tooltip'] ?? null) : null"
            />
        @endif
    </div>
@endif
