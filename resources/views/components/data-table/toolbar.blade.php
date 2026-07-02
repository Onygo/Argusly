@props([
    'title' => null,
    'description' => null,
])

<div {{ $attributes->class('pl-data-table-toolbar') }}>
    @if (filled($title) || filled($description))
        <div class="pl-data-table-toolbar__summary">
            @if (filled($title))
                <h2 class="pl-data-table-toolbar__title">{{ $title }}</h2>
            @endif
            @if (filled($description))
                <p class="pl-data-table-toolbar__description">{{ $description }}</p>
            @endif
        </div>
    @endif

    @isset($search)
        <div class="pl-data-table-toolbar__search">{{ $search }}</div>
    @endif

    @isset($filters)
        <div class="pl-data-table-toolbar__filters">{{ $filters }}</div>
    @endif

    @isset($actions)
        <div class="pl-data-table-toolbar__actions">{{ $actions }}</div>
    @endif

    {{ $slot }}
</div>
