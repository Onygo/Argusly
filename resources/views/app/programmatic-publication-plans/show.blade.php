@extends('layouts.app', ['title' => 'Publication Plan'])

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.programmatic-publication-plans.index', ['workspace_id' => $workspace->id]) }}" class="text-sm font-medium text-textSecondary hover:text-textPrimary">Publication Plans</a>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-textPrimary">{{ $plan->name }}</h1>
                <p class="mt-1 text-sm text-textSecondary">{{ str($plan->status)->headline() }} · {{ str($plan->cadence)->replace('_', ' ')->headline() }}</p>
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
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-textSecondary">
                            <th class="px-4 py-3">Content</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Planned publish</th>
                            <th class="px-4 py-3">Priority</th>
                            <th class="px-4 py-3">Risk</th>
                            <th class="px-4 py-3">Readiness</th>
                            <th class="px-4 py-3">Publication</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($plan->items as $item)
                            @php($publication = $item->linkedPublication())
                            @php($conflict = data_get($item->metadata, 'conflict'))
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('app.content.show', $item->content) }}" class="font-medium text-textPrimary hover:text-primary">{{ $item->title }}</a>
                                    <div class="mt-1 text-xs text-textMuted">{{ $item->slug ?: 'n/a' }}</div>
                                </td>
                                <td class="px-4 py-3 text-textSecondary">{{ str($item->status)->headline() }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ $item->planned_publish_at?->format('Y-m-d H:i') ?? 'manual' }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ number_format((float) $item->priority_score, 1) }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ number_format((float) $item->publication_risk_score, 1) }}</td>
                                <td class="px-4 py-3 text-textSecondary">
                                    <a href="{{ route('app.programmatic-publication-readiness.show', $item->readiness) }}" class="text-primary hover:underline">{{ str($item->readiness?->status)->headline() }}</a>
                                </td>
                                <td class="px-4 py-3 text-textSecondary">
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
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-textMuted">No plan items yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
