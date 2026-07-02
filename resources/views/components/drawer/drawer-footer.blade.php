@props([
    'actions' => [],
])

@php
    $normalizedActions = collect($actions)
        ->map(fn ($action) => is_string($action) ? ['key' => $action, 'label' => $action] : $action)
        ->filter(fn ($action) => filled($action['label'] ?? $action['key'] ?? null))
        ->values();
@endphp

@if ($slot->isNotEmpty() || $normalizedActions->isNotEmpty())
    <footer {{ $attributes->class('shrink-0 border-t border-divider p-5') }}>
        <div class="flex flex-wrap items-center justify-end gap-2">
            @if ($slot->isNotEmpty())
                {{ $slot }}
            @else
                @foreach ($normalizedActions as $action)
                    <button
                        type="button"
                        class="pl-btn-secondary"
                        data-drawer-action="{{ $action['key'] ?? '' }}"
                        @disabled(data_get($action, 'disabled', false))
                    >
                        {{ $action['label'] ?? $action['key'] }}
                    </button>
                @endforeach
            @endif
        </div>
    </footer>
@endif
