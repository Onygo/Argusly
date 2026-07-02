@extends('layouts.app', ['title' => 'Publication Plans'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Programmatic Publication Plans</x-slot:title>
        <x-slot:description>Planning records for approved programmatic content. No publication is scheduled from here.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
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

        <x-data-table label="Programmatic publication plans" description="Publication planning records with status, cadence, items, planning window, and growth program.">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Plan</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Cadence</x-data-table.cell>
                        <x-data-table.cell heading>Items</x-data-table.cell>
                        <x-data-table.cell heading>Window</x-data-table.cell>
                        <x-data-table.cell heading>Growth Program</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                        @forelse ($plans as $plan)
                            <x-data-table.row>
                                <x-data-table.cell label="Plan" class="font-medium text-textPrimary">
                                    <a href="{{ route('app.programmatic-publication-plans.show', $plan) }}" class="hover:text-primary">{{ $plan->name }}</a>
                                </x-data-table.cell>
                                <x-data-table.cell label="Status">
                                    <x-data-table.badge :label="str($plan->status)->headline()" />
                                </x-data-table.cell>
                                <x-data-table.cell label="Cadence" class="text-textSecondary">{{ str($plan->cadence)->replace('_', ' ')->headline() }}</x-data-table.cell>
                                <x-data-table.cell label="Items" class="text-textSecondary">{{ $plan->items_count }}</x-data-table.cell>
                                <x-data-table.cell label="Window" class="text-textSecondary">{{ $plan->planned_start_at?->format('Y-m-d') ?? 'n/a' }} - {{ $plan->planned_end_at?->format('Y-m-d') ?? 'n/a' }}</x-data-table.cell>
                                <x-data-table.cell label="Growth Program" class="text-textSecondary">
                                    @if ($plan->growthProgram)
                                        <a href="{{ route('app.growth-programs.show', $plan->growthProgram) }}" class="text-primary hover:underline">{{ $plan->growthProgram->name }}</a>
                                    @else
                                        n/a
                                    @endif
                                </x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="6" title="No publication plans yet" />
                        @endforelse
                </tbody>
            <x-slot:pagination>{{ $plans->links() }}</x-slot:pagination>
        </x-data-table>
    </div>
@endsection
