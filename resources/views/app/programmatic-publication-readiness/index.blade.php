@extends('layouts.app', ['title' => 'Publication Readiness'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Programmatic Publication Readiness</x-slot:title>
        <x-slot:description>Readiness gates for converted programmatic content.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <form method="GET" action="{{ route('app.programmatic-publication-readiness.index') }}" class="flex flex-wrap gap-2">
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

        <x-data-table label="Programmatic publication readiness" description="Publication readiness records with status, readiness score, SEO, schema, risk, and growth program.">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Content</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Readiness</x-data-table.cell>
                        <x-data-table.cell heading>SEO</x-data-table.cell>
                        <x-data-table.cell heading>Schema</x-data-table.cell>
                        <x-data-table.cell heading>Risk</x-data-table.cell>
                        <x-data-table.cell heading>Growth Program</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                        @forelse ($readinessRecords as $readiness)
                            <x-data-table.row>
                                <x-data-table.cell label="Content" class="font-medium text-textPrimary">
                                    <a href="{{ route('app.programmatic-publication-readiness.show', $readiness) }}" class="hover:text-primary">{{ $readiness->content?->title }}</a>
                                </x-data-table.cell>
                                <x-data-table.cell label="Status">
                                    <x-data-table.badge :label="str($readiness->status)->headline()" />
                                </x-data-table.cell>
                                <x-data-table.cell label="Readiness" class="text-textSecondary">{{ number_format((float) $readiness->readiness_score, 1) }}</x-data-table.cell>
                                <x-data-table.cell label="SEO" class="text-textSecondary">{{ number_format((float) $readiness->seo_score, 1) }}</x-data-table.cell>
                                <x-data-table.cell label="Schema" class="text-textSecondary">{{ number_format((float) $readiness->schema_score, 1) }}</x-data-table.cell>
                                <x-data-table.cell label="Risk" class="text-textSecondary">{{ number_format((float) $readiness->publication_risk_score, 1) }}</x-data-table.cell>
                                <x-data-table.cell label="Growth Program" class="text-textSecondary">
                                    @if ($readiness->growthProgram)
                                        <a href="{{ route('app.growth-programs.show', $readiness->growthProgram) }}" class="text-primary hover:underline">{{ $readiness->growthProgram->name }}</a>
                                    @else
                                        n/a
                                    @endif
                                </x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="7" title="No publication readiness records yet" />
                        @endforelse
                </tbody>
            <x-slot:pagination>{{ $readinessRecords->links() }}</x-slot:pagination>
        </x-data-table>
    </div>
@endsection
