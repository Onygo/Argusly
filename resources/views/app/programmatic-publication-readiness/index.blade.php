@extends('layouts.app', ['title' => 'Publication Readiness'])

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Programmatic Publication Readiness</h1>
                <p class="mt-1 text-sm text-textSecondary">Readiness gates for converted programmatic content.</p>
            </div>
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

        <section class="rounded-lg border border-border bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-textSecondary">
                            <th class="px-4 py-3">Content</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Readiness</th>
                            <th class="px-4 py-3">SEO</th>
                            <th class="px-4 py-3">Schema</th>
                            <th class="px-4 py-3">Risk</th>
                            <th class="px-4 py-3">Growth Program</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($readinessRecords as $readiness)
                            <tr>
                                <td class="px-4 py-3 font-medium text-textPrimary">
                                    <a href="{{ route('app.programmatic-publication-readiness.show', $readiness) }}" class="hover:text-primary">{{ $readiness->content?->title }}</a>
                                </td>
                                <td class="px-4 py-3 text-textSecondary">{{ str($readiness->status)->headline() }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ number_format((float) $readiness->readiness_score, 1) }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ number_format((float) $readiness->seo_score, 1) }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ number_format((float) $readiness->schema_score, 1) }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ number_format((float) $readiness->publication_risk_score, 1) }}</td>
                                <td class="px-4 py-3 text-textSecondary">
                                    @if ($readiness->growthProgram)
                                        <a href="{{ route('app.growth-programs.show', $readiness->growthProgram) }}" class="text-primary hover:underline">{{ $readiness->growthProgram->name }}</a>
                                    @else
                                        n/a
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-textMuted">No publication readiness records yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-border px-4 py-3">{{ $readinessRecords->links() }}</div>
        </section>
    </div>
@endsection
