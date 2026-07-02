@extends('layouts.app', ['title' => 'Programmatic Draft Reviews'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Programmatic Draft Reviews</x-slot:title>
        <x-slot:description>Quality gates for generated programmatic drafts.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <form method="GET" action="{{ route('app.programmatic-draft-reviews.index') }}" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
                <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">
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

        <x-data-table label="Programmatic draft reviews" description="Generated draft reviews with status and quality scores." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Draft</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Overall</x-data-table.cell>
                        <x-data-table.cell heading>SEO</x-data-table.cell>
                        <x-data-table.cell heading>AI</x-data-table.cell>
                        <x-data-table.cell heading>Risk</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                        @forelse ($reviews as $review)
                            <x-data-table.row>
                                <x-data-table.cell label="Draft" class="font-medium text-textPrimary"><a href="{{ route('app.programmatic-draft-reviews.show', $review) }}" class="hover:text-primary">{{ $review->draft?->title }}</a></x-data-table.cell>
                                <x-data-table.cell label="Status">
                                    <x-data-table.badge :label="str($review->status)->headline()" />
                                </x-data-table.cell>
                                <x-data-table.cell label="Overall" class="text-textSecondary">{{ number_format((float) $review->overall_score, 1) }}</x-data-table.cell>
                                <x-data-table.cell label="SEO" class="text-textSecondary">{{ number_format((float) $review->seo_score, 1) }}</x-data-table.cell>
                                <x-data-table.cell label="AI" class="text-textSecondary">{{ number_format((float) $review->ai_visibility_score, 1) }}</x-data-table.cell>
                                <x-data-table.cell label="Risk" class="text-textSecondary">{{ number_format((float) $review->risk_score, 1) }}</x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="6" title="No draft reviews yet" />
                        @endforelse
                </tbody>
            <x-slot:pagination>{{ $reviews->links() }}</x-slot:pagination>
        </x-data-table>
    </div>
@endsection
