@extends('layouts.app', ['title' => 'Publication Plans'])

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Programmatic Publication Plans</h1>
                <p class="mt-1 text-sm text-textSecondary">Planning records for approved programmatic content. No publication is scheduled from here.</p>
            </div>
            <form method="GET" action="{{ route('app.programmatic-publication-plans.index') }}" class="flex flex-wrap gap-2">
                <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
                <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                    @endforeach
                </select>
                <button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Filter</button>
            </form>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <section class="rounded-lg border border-border bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-textSecondary">
                            <th class="px-4 py-3">Plan</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Cadence</th>
                            <th class="px-4 py-3">Items</th>
                            <th class="px-4 py-3">Window</th>
                            <th class="px-4 py-3">Growth Program</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($plans as $plan)
                            <tr>
                                <td class="px-4 py-3 font-medium text-textPrimary">
                                    <a href="{{ route('app.programmatic-publication-plans.show', $plan) }}" class="hover:text-primary">{{ $plan->name }}</a>
                                </td>
                                <td class="px-4 py-3 text-textSecondary">{{ str($plan->status)->headline() }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ str($plan->cadence)->replace('_', ' ')->headline() }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ $plan->items_count }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ $plan->planned_start_at?->format('Y-m-d') ?? 'n/a' }} - {{ $plan->planned_end_at?->format('Y-m-d') ?? 'n/a' }}</td>
                                <td class="px-4 py-3 text-textSecondary">
                                    @if ($plan->growthProgram)
                                        <a href="{{ route('app.growth-programs.show', $plan->growthProgram) }}" class="text-primary hover:underline">{{ $plan->growthProgram->name }}</a>
                                    @else
                                        n/a
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-textMuted">No publication plans yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-border px-4 py-3">{{ $plans->links() }}</div>
        </section>
    </div>
@endsection
