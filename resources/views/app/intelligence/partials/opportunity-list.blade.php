@if ($items->isEmpty())
    <x-dashboard.empty-state title="No opportunities" message="This lane has no active opportunities for the current brand context." />
@else
    <div class="space-y-3">
        @foreach ($items->take(6) as $item)
            <div class="rounded-md border border-line bg-white p-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-ink">{{ $item['title'] }}</p>
                        <p class="mt-1 text-xs text-muted">{{ str($item['priority'])->headline() }} · {{ str($item['complexity'])->headline() }} complexity</p>
                    </div>
                    <x-ui.badge>{{ $item['score'] }}</x-ui.badge>
                </div>
                <p class="mt-3 text-sm leading-6 text-muted">{{ $item['summary'] }}</p>
            </div>
        @endforeach
    </div>
@endif
