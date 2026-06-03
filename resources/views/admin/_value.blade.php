@props(['row', 'column'])

@php
    $value = data_get($row, $column);
    if ($value instanceof \Illuminate\Support\Carbon) {
        $value = $value->format('Y-m-d H:i');
    } elseif (is_array($value)) {
        $value = json_encode($value, JSON_PRETTY_PRINT);
    } elseif (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
@endphp

@if (str_contains($column, 'status') || $column === 'level')
    @include('admin._status', ['value' => $value])
@elseif (is_string($value) && str_starts_with(trim($value), '{'))
    <pre class="max-h-28 overflow-auto whitespace-pre-wrap rounded-md bg-panel p-2 text-xs text-muted">{{ $value }}</pre>
@else
    <span class="text-sm text-ink">{{ filled($value) ? $value : 'n/a' }}</span>
@endif
