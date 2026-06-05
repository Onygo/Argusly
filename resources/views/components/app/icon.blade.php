@props(['name' => 'circle', 'class' => 'size-4'])

@php
    $paths = [
        'layout-dashboard' => '<path d="M3 3h7v7H3z"/><path d="M14 3h7v4h-7z"/><path d="M14 11h7v10h-7z"/><path d="M3 14h7v7H3z"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m13 5 7 7-7 7"/>',
        'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'trend-up' => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
        'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'circle-dot' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/>',
        'sparkles' => '<path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="m19 16 .8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/><path d="m5 2 .8 2.2L8 5l-2.2.8L5 8l-.8-2.2L2 5l2.2-.8z"/>',
        'radar' => '<circle cx="12" cy="12" r="9"/><path d="M12 3v9l6 3"/><path d="M12 12h9"/><circle cx="12" cy="12" r="3"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'file-text' => '<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v5h5"/><path d="M9 13h6"/><path d="M9 17h6"/><path d="M9 9h2"/>',
        'megaphone' => '<path d="M3 11v4a2 2 0 0 0 2 2h2l3 4v-4h2l7 3V6l-7 3H5a2 2 0 0 0-2 2z"/><path d="M19 9a4 4 0 0 1 0 8"/>',
        'bot' => '<rect x="5" y="8" width="14" height="11" rx="3"/><path d="M12 4v4"/><path d="M8 13h.01"/><path d="M16 13h.01"/><path d="M9 17h6"/>',
        'network' => '<circle cx="6" cy="6" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="12" cy="18" r="3"/><path d="m8.5 8.5 2.5 6"/><path d="m15.5 8.5-2.5 6"/><path d="M9 6h6"/>',
        'image' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8" cy="10" r="2"/><path d="m21 15-5-5L5 19"/>',
        'bar-chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5"/><path d="M12 16V7"/><path d="M16 16v-8"/>',
        'settings' => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'panel-left-close' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/><path d="m16 10-2 2 2 2"/>',
        'panel-left-open' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/><path d="m14 10 2 2-2 2"/>',
        'bell' => '<path d="M10.3 21a2 2 0 0 0 3.4 0"/><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>',
        'coins' => '<circle cx="8" cy="8" r="5"/><path d="M18.1 10.6A5 5 0 1 1 10.6 18"/><path d="M8 6v4"/><path d="M6 8h4"/><path d="M18 15v4"/><path d="M16 17h4"/>',
        'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {!! $paths[$name] ?? '<circle cx="12" cy="12" r="9"/>' !!}
</svg>
