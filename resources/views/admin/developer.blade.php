<x-app.layout :title="$config['title']" :show-workspace-header="false">
    @include('admin._nav')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-ink">{{ $config['title'] }}</h1>
            @if (in_array($tool, ['graph-nodes', 'graph-edges'], true))
                <p class="mt-2 text-sm text-muted">Graph rebuild and verify command statuses are placeholders; node and edge inspection is backed by the projection tables.</p>
            @endif
        </div>
        @if (! empty($config['filters']))
            <form method="GET" class="flex flex-wrap gap-2">
                @foreach ($config['filters'] as $filter)
                    <input name="{{ $filter }}" value="{{ request($filter) }}" placeholder="{{ str($filter)->headline() }}" class="rounded-md border border-line px-3 py-2 text-sm">
                @endforeach
                <button class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Filter</button>
            </form>
        @endif
    </div>
    <div class="mt-4">@include('admin._table', ['rows' => $config['rows'], 'columns' => $config['columns']])</div>
</x-app.layout>
