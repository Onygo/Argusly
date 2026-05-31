@php
    $outgoing = $model->outgoingRelationships ?? collect();
    $incoming = $model->incomingRelationships ?? collect();
@endphp

@if ($outgoing->isEmpty() && $incoming->isEmpty())
    <x-dashboard.empty-state title="No relationships" message="Connect this record to people, organizations, media, experts or stakeholders from the relationship graph." />
@else
    <div class="space-y-3">
        @foreach ($outgoing as $relationship)
            <div class="flex items-center justify-between gap-4 rounded-lg border border-line bg-panel p-4">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-ink">
                        {{ $relationship->to instanceof \App\Models\Contact ? $relationship->to->display_name : $relationship->to->name }}
                    </p>
                    <p class="mt-1 text-xs text-muted">Outgoing</p>
                </div>
                <x-ui.badge>{{ str($relationship->relationship_type)->headline() }}</x-ui.badge>
            </div>
        @endforeach
        @foreach ($incoming as $relationship)
            <div class="flex items-center justify-between gap-4 rounded-lg border border-line bg-panel p-4">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-ink">
                        {{ $relationship->from instanceof \App\Models\Contact ? $relationship->from->display_name : $relationship->from->name }}
                    </p>
                    <p class="mt-1 text-xs text-muted">Incoming</p>
                </div>
                <x-ui.badge>{{ str($relationship->relationship_type)->headline() }}</x-ui.badge>
            </div>
        @endforeach
    </div>
@endif
