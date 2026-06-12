@extends('layouts.app', ['title' => 'Programmatic Draft Reviews'])

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Programmatic Draft Reviews</h1>
                <p class="mt-1 text-sm text-textSecondary">Quality gates for generated programmatic drafts.</p>
            </div>
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

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-textSecondary">
                            <th class="py-2 pr-4">Draft</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Overall</th>
                            <th class="py-2 pr-4">SEO</th>
                            <th class="py-2 pr-4">AI</th>
                            <th class="py-2 pr-4">Risk</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($reviews as $review)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-textPrimary"><a href="{{ route('app.programmatic-draft-reviews.show', $review) }}" class="hover:text-primary">{{ $review->draft?->title }}</a></td>
                                <td class="py-2 pr-4 text-textSecondary">{{ str($review->status)->headline() }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ number_format((float) $review->overall_score, 1) }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ number_format((float) $review->seo_score, 1) }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ number_format((float) $review->ai_visibility_score, 1) }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ number_format((float) $review->risk_score, 1) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-sm text-textMuted">No draft reviews yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $reviews->links() }}</div>
        </section>
    </div>
@endsection
