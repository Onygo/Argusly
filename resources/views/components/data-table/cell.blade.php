@props([
    'heading' => false,
    'label' => null,
    'align' => 'left',
    'nowrap' => false,
    'colspan' => null,
    'scope' => 'col',
])

@php
    $tag = $heading ? 'th' : 'td';
@endphp

<{{ $tag }}
    @if ($heading) scope="{{ $scope }}" @endif
    @if (! $heading && filled($label)) data-label="{{ $label }}" @endif
    @if ($colspan) colspan="{{ $colspan }}" @endif
    {{ $attributes->class([
        'pl-data-table__cell',
        'pl-data-table__cell--heading' => $heading,
        'pl-data-table__cell--right' => $align === 'right',
        'pl-data-table__cell--center' => $align === 'center',
        'pl-data-table__cell--nowrap' => $nowrap,
    ]) }}
>
    {{ $slot }}
</{{ $tag }}>
