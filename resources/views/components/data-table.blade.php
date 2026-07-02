@props([
    'label',
    'description' => null,
    'density' => 'default',
    'sticky' => false,
    'loading' => false,
    'skeletonRows' => 5,
    'maxHeight' => null,
    'tableClass' => '',
])

@php
    $tableId = $attributes->get('id') ?: 'data-table-'.\Illuminate\Support\Str::uuid();
    $hasToolbar = isset($toolbar) || isset($search) || isset($filters) || isset($actions);
@endphp

<section {{ $attributes->except('id')->class([
    'pl-data-table',
    'pl-data-table--compact' => $density === 'compact',
    'pl-data-table--comfortable' => $density === 'comfortable',
]) }}>
    @if ($hasToolbar)
        @isset($toolbar)
            {{ $toolbar }}
        @else
            <x-data-table.toolbar>
                @isset($search)
                    <x-slot:search>{{ $search }}</x-slot:search>
                @endif
                @isset($filters)
                    <x-slot:filters>{{ $filters }}</x-slot:filters>
                @endif
                @isset($actions)
                    <x-slot:actions>{{ $actions }}</x-slot:actions>
                @endif
            </x-data-table.toolbar>
        @endif
    @endif

    @isset($bulkActions)
        <div class="pl-data-table__bulk">
            {{ $bulkActions }}
        </div>
    @endif

    <div
        class="pl-data-table__scroller"
        @if ($maxHeight) style="max-height: {{ $maxHeight }};" @endif
    >
        <table id="{{ $tableId }}" class="pl-data-table__table {{ $tableClass }}" aria-label="{{ $label }}" @if ($description) aria-describedby="{{ $tableId }}-description" @endif>
            @if ($description)
                <caption id="{{ $tableId }}-description" class="sr-only">{{ $description }}</caption>
            @endif

            @if ($loading)
                <tbody>
                    @for ($i = 0; $i < $skeletonRows; $i++)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="pl-loading-skeleton__row w-full"></span>
                            </td>
                        </tr>
                    @endfor
                </tbody>
            @else
                {{ $slot }}
            @endif
        </table>
    </div>

    @isset($pagination)
        <x-data-table.pagination>{{ $pagination }}</x-data-table.pagination>
    @endif
</section>
