@props([
    'colspan' => 1,
    'title' => 'No results found',
    'description' => null,
    'icon' => 'inbox',
])

<x-data-table.row>
    <x-data-table.cell :colspan="$colspan" class="pl-data-table__empty-cell">
        <x-empty-state :title="$title" :description="$description" :icon="$icon">
            {{ $slot }}
        </x-empty-state>
    </x-data-table.cell>
</x-data-table.row>
