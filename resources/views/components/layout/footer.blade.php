@props([
    'statement' => null,
    'align' => 'center',
])

@php
    $alignClass = $align === 'left' ? 'text-left' : 'text-center';
@endphp

<div {{ $attributes->class('space-y-1') }}>
    <div class="text-sm text-textSecondary {{ $alignClass }}">
        @if ($statement)
            {{ $statement }}
        @else
            <span>{{ \App\Support\Brand::product() }}</span>
            @if (config('brand.show_parent_branding', true))
                <span class="mx-1">by</span>
                <span class="font-medium text-textPrimary">{{ \App\Support\Brand::parentLinked() }}</span>
            @endif
        @endif
    </div>

    <div class="text-xs text-textMuted {{ $alignClass }}">
        &copy; {{ date('Y') }} {{ config('brand.show_parent_branding', true) ? \App\Support\Brand::parentLinked() : \App\Support\Brand::product() }}
    </div>
</div>
