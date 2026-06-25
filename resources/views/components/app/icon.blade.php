@props([
    'name',
])

@if ($name === 'linkedin')
    <svg
        {{ $attributes->class('shrink-0') }}
        viewBox="0 0 24 24"
        fill="currentColor"
        aria-hidden="true"
    >
        <path d="M20.45 20.45h-3.56v-5.57c0-1.33-.03-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.12 2.06 2.06 0 0 1 0 4.12ZM7.11 20.45H3.56V9h3.55v11.45ZM22.23 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.23 0Z" />
    </svg>
@elseif ($name === 'instagram')
    <svg
        {{ $attributes->class('shrink-0') }}
        viewBox="0 0 24 24"
        fill="none"
        aria-hidden="true"
    >
        <rect x="3" y="3" width="18" height="18" rx="5" stroke="currentColor" stroke-width="2" />
        <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2" />
        <circle cx="17.5" cy="6.5" r="1.25" fill="currentColor" />
    </svg>
@else
    <i data-lucide="{{ $name }}" {{ $attributes->class('shrink-0') }}></i>
@endif
