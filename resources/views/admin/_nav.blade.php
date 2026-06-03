@php
    $pendingPilotSignupCount = \Illuminate\Support\Facades\Schema::hasTable('pilot_signups')
        ? \Illuminate\Support\Facades\DB::table('pilot_signups')->whereIn('status', ['pending', 'reviewing'])->count()
        : 0;

    $primary = [
        ['label' => 'Overview', 'route' => 'admin.overview'],
        ['label' => 'Accounts', 'route' => 'admin.accounts'],
        ['label' => 'Brands', 'route' => 'admin.brands'],
        ['label' => 'Users', 'route' => 'admin.users'],
        ['label' => 'Modules', 'route' => 'admin.modules'],
        ['label' => 'Subscriptions', 'route' => 'admin.subscriptions'],
        ['label' => 'Credits', 'route' => 'admin.credits'],
        ['label' => 'Integrations', 'route' => 'admin.integrations'],
        ['label' => 'Connectors', 'route' => 'admin.connectors'],
        ['label' => 'Publishing Channels', 'route' => 'admin.publishing-channels'],
        ['label' => 'Pilot Signups', 'route' => 'admin.pilot-signups', 'badge' => $pendingPilotSignupCount],
    ];
    $developer = [
        ['label' => 'Domain Events', 'route' => 'admin.developer-tools.show', 'params' => ['domain-events']],
        ['label' => 'Outbox Messages', 'route' => 'admin.developer-tools.show', 'params' => ['outbox-messages']],
        ['label' => 'Activity Logs', 'route' => 'admin.developer-tools.show', 'params' => ['activity-logs']],
        ['label' => 'Connector Logs', 'route' => 'admin.developer-tools.show', 'params' => ['connector-logs']],
        ['label' => 'Source Syncs', 'route' => 'admin.developer-tools.show', 'params' => ['source-syncs']],
        ['label' => 'Graph Nodes', 'route' => 'admin.developer-tools.show', 'params' => ['graph-nodes']],
        ['label' => 'Graph Edges', 'route' => 'admin.developer-tools.show', 'params' => ['graph-edges']],
        ['label' => 'System Health', 'route' => 'admin.developer-tools.show', 'params' => ['system-health']],
    ];
@endphp

<div class="mb-6 rounded-md border border-line bg-white p-3">
    <div class="flex flex-wrap gap-2">
        @foreach ($primary as $item)
            <a href="{{ route($item['route']) }}" @class([
                'rounded-md px-3 py-2 text-sm font-semibold transition',
                'bg-blue text-white' => request()->routeIs($item['route']),
                'text-muted hover:bg-panel hover:text-ink' => ! request()->routeIs($item['route']),
            ])>
                {{ $item['label'] }}
                @if (($item['badge'] ?? 0) > 0)
                    <span class="ml-1 inline-flex min-w-5 justify-center rounded-full bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">{{ $item['badge'] }}</span>
                @endif
            </a>
        @endforeach
    </div>
    <div class="mt-3 border-t border-line pt-3">
        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.12em] text-muted">Developer Tools</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($developer as $item)
                <a href="{{ route($item['route'], $item['params']) }}" @class([
                    'rounded-md px-3 py-2 text-sm font-semibold transition',
                    'bg-ink text-white' => request()->fullUrlIs(route($item['route'], $item['params'])),
                    'text-muted hover:bg-panel hover:text-ink' => ! request()->fullUrlIs(route($item['route'], $item['params'])),
                ])>{{ $item['label'] }}</a>
            @endforeach
        </div>
    </div>
</div>
