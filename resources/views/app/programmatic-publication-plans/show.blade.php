@extends('layouts.app', ['title' => 'Publication Plan'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>{{ $plan->name }}</x-slot:title>
        <x-slot:description>{{ str($plan->status)->headline() }} · {{ str($plan->cadence)->replace('_', ' ')->headline() }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.programmatic-publication-plans.index', ['workspace_id' => $workspace->id]) }}" class="text-sm font-medium text-textSecondary hover:text-textPrimary">Publication Plans</a>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('approve', $plan)
                    <form method="POST" action="{{ route('app.programmatic-publication-plans.approve', $plan) }}">@csrf<button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Approve Plan</button></form>
                @endcan
                @if ($plan->status === \App\Models\ProgrammaticPublicationPlan::STATUS_APPROVED)
                    @can('schedule', $plan)
                        <form method="POST" action="{{ route('app.programmatic-publication-plans.schedule', $plan) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Create Scheduled Publication Records</button></form>
                    @endcan
                @endif
                @can('prepare', $plan)
                    <form method="POST" action="{{ route('app.programmatic-publication-plans.recalculate', $plan) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Recalculate Cadence</button></form>
                @endcan
                @can('cancel', $plan)
                    <form method="POST" action="{{ route('app.programmatic-publication-plans.cancel', $plan) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Cancel Scheduled Plan</button></form>
                @endcan
            </div>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif
        @if ($errors->any())
            <x-alert type="error">{{ $errors->first() }}</x-alert>
        @endif

        <section class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-sm font-semibold text-textPrimary">Plan Summary</h2>
            <dl class="mt-4 grid gap-4 md:grid-cols-4 text-sm">
                <div><dt class="text-textSecondary">Destination</dt><dd class="mt-1 font-medium text-textPrimary">{{ $plan->destination?->name ?? 'n/a' }}</dd></div>
                <div><dt class="text-textSecondary">Planned start</dt><dd class="mt-1 font-medium text-textPrimary">{{ $plan->planned_start_at?->format('Y-m-d H:i') ?? 'n/a' }}</dd></div>
                <div><dt class="text-textSecondary">Planned end</dt><dd class="mt-1 font-medium text-textPrimary">{{ $plan->planned_end_at?->format('Y-m-d H:i') ?? 'n/a' }}</dd></div>
                <div><dt class="text-textSecondary">Items</dt><dd class="mt-1 font-medium text-textPrimary">{{ $plan->total_items }}</dd></div>
            </dl>
            @if ($plan->description)
                <p class="mt-4 text-sm leading-6 text-textSecondary">{{ $plan->description }}</p>
            @endif
            <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">This prepares scheduled publication records. It does not publish content live.</p>
            @if (! $plan->destination_id)
                <p class="mt-3 rounded-md border border-border bg-background px-3 py-2 text-xs text-textSecondary">Choose a destination before scheduling this plan.</p>
            @endif
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-sm font-semibold text-textPrimary">Plan Items</h2>
            </div>
            <x-data-table label="Publication plan items" description="Planned content items with schedule, priority, risk, readiness, and linked publication state." density="compact" class="border-0 rounded-none shadow-none" table-class="min-w-full text-sm">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Content</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Planned publish</x-data-table.cell>
                        <x-data-table.cell heading>Priority</x-data-table.cell>
                        <x-data-table.cell heading>Risk</x-data-table.cell>
                        <x-data-table.cell heading>Readiness</x-data-table.cell>
                        <x-data-table.cell heading>Publication</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody class="divide-y divide-border">
                    @forelse ($plan->items as $item)
                        @php($publication = $item->linkedPublication())
                        @php($conflict = data_get($item->metadata, 'conflict'))
                        <x-data-table.row>
                            <x-data-table.cell label="Content">
                                <a href="{{ route('app.content.show', $item->content) }}" class="font-medium text-textPrimary hover:text-primary">{{ $item->title }}</a>
                                <div class="mt-1 text-xs text-textMuted">{{ $item->slug ?: 'n/a' }}</div>
                            </x-data-table.cell>
                            <x-data-table.cell label="Status" class="text-textSecondary">{{ str($item->status)->headline() }}</x-data-table.cell>
                            <x-data-table.cell label="Planned publish" class="text-textSecondary">{{ $item->planned_publish_at?->format('Y-m-d H:i') ?? 'manual' }}</x-data-table.cell>
                            <x-data-table.cell label="Priority" class="text-textSecondary">{{ number_format((float) $item->priority_score, 1) }}</x-data-table.cell>
                            <x-data-table.cell label="Risk" class="text-textSecondary">{{ number_format((float) $item->publication_risk_score, 1) }}</x-data-table.cell>
                            <x-data-table.cell label="Readiness" class="text-textSecondary">
                                <a href="{{ route('app.programmatic-publication-readiness.show', $item->readiness) }}" class="text-primary hover:underline">{{ str($item->readiness?->status)->headline() }}</a>
                            </x-data-table.cell>
                            <x-data-table.cell label="Publication" class="text-textSecondary">
                                @if ($publication)
                                    <span class="font-medium text-textPrimary">{{ $publication->provider }}</span>
                                    <div class="mt-1 text-xs">{{ $publication->remote_status ?: 'draft' }} · {{ $publication->delivery_status }}</div>
                                    @if ($publication->scheduled_publish_at)
                                        <div class="mt-1 text-xs">Scheduled {{ $publication->scheduled_publish_at->format('Y-m-d H:i') }}</div>
                                    @endif
                                @else
                                    not scheduled
                                @endif
                                @if ($conflict)
                                    <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-900">
                                        {{ data_get($conflict, 'message') ?: str(data_get($conflict, 'reason', 'conflict'))->replace('_', ' ')->headline() }}
                                    </div>
                                @endif
                            </x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="7" title="No plan items yet" />
                    @endforelse
                </tbody>
            </x-data-table>
        </section>
    </div>
@endsection
