@props([
    'status' => null,
    'color' => 'slate',
    'icon' => null,
    'label' => null,
    'tooltip' => null,
    'size' => 'sm',
    'dot' => false,
])

@php
    // Support passing enum objects directly
    if (is_object($status)) {
        $label = $label ?? (method_exists($status, 'label') ? $status->label() : (string) $status->value);
        $color = method_exists($status, 'color') ? $status->color() : $color;
        $icon = $icon ?? (method_exists($status, 'icon') ? $status->icon() : null);
    } elseif (is_string($status) && $label === null) {
        $label = ucfirst(str_replace(['_', '-'], ' ', $status));
    }

    $iconAliases = [
        'archive-box' => 'archive',
        'arrow-path' => 'refresh-cw',
        'arrow-path-rounded-square' => 'refresh-cw',
        'document-text' => 'file-text',
        'exclamation-triangle' => 'triangle-alert',
        'globe-alt' => 'globe',
        'lock-closed' => 'lock',
        'paper-airplane' => 'send',
        'question-mark-circle' => 'circle-help',
    ];

    $resolvedIcon = is_string($icon) ? ($iconAliases[$icon] ?? $icon) : null;

    // Color mapping to Tailwind classes
    $colorClasses = match ($color) {
        'green', 'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'red', 'error', 'danger' => 'bg-red-50 text-red-700 border-red-200',
        'amber', 'warning', 'yellow' => 'bg-amber-50 text-amber-700 border-amber-200',
        'sky', 'blue', 'info' => 'bg-sky-50 text-sky-700 border-sky-200',
        'orange' => 'bg-orange-50 text-orange-700 border-orange-200',
        'purple' => 'bg-purple-50 text-purple-700 border-purple-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'gray', 'slate' => 'bg-slate-50 text-slate-600 border-slate-200',
        default => 'bg-slate-50 text-slate-600 border-slate-200',
    };

    // Dot color for the indicator
    $dotColorClasses = match ($color) {
        'green', 'success', 'emerald' => 'bg-emerald-500',
        'red', 'error', 'danger' => 'bg-red-500',
        'amber', 'warning', 'yellow' => 'bg-amber-500',
        'sky', 'blue', 'info' => 'bg-sky-500',
        'orange' => 'bg-orange-500',
        'purple' => 'bg-purple-500',
        'gray', 'slate' => 'bg-slate-400',
        default => 'bg-slate-400',
    };

    // Size classes
    $sizeClasses = match ($size) {
        'xs' => 'px-1.5 py-0.5 text-[10px]',
        'sm' => 'px-2 py-0.5 text-xs',
        'md' => 'px-2.5 py-1 text-sm',
        'lg' => 'px-3 py-1.5 text-base',
        default => 'px-2 py-0.5 text-xs',
    };

    $iconSizeClasses = match ($size) {
        'xs' => 'h-2.5 w-2.5',
        'sm' => 'h-3 w-3',
        'md' => 'h-4 w-4',
        'lg' => 'h-5 w-5',
        default => 'h-3 w-3',
    };
@endphp

<span
    {{ $attributes->class([
        'inline-flex max-w-full items-center gap-1 rounded-md border font-medium',
        $colorClasses,
        $sizeClasses,
    ]) }}
    @if($tooltip)
        title="{{ $tooltip }}"
    @endif
>
    @if($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ $dotColorClasses }}"></span>
    @elseif($resolvedIcon)
        <i data-lucide="{{ $resolvedIcon }}" class="{{ $iconSizeClasses }} shrink-0" aria-hidden="true"></i>
    @endif

    <span class="min-w-0 truncate">{{ $label ?? $slot }}</span>
</span>
