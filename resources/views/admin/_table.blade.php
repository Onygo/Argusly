@props(['rows', 'columns', 'empty' => 'No records found.'])

<div class="overflow-hidden rounded-md border border-line bg-white">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-left">
            <thead class="bg-panel">
                <tr>
                    @foreach ($columns as $column)
                        <th class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.08em] text-muted">{{ str($column)->afterLast('.')->headline() }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td class="max-w-xs px-4 py-3 align-top">
                                @include('admin._value', ['row' => $row, 'column' => $column])
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) }}" class="px-4 py-10 text-center text-sm text-muted">{{ $empty }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($rows instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-4">{{ $rows->links() }}</div>
@endif
