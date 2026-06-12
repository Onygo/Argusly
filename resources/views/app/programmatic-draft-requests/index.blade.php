@extends('layouts.app', ['title' => 'Programmatic Draft Requests'])

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Programmatic Draft Requests</h1>
                <p class="mt-1 text-sm text-textSecondary">Controlled draft generation requests prepared from converted programmatic briefs.</p>
            </div>
            <form method="GET" action="{{ route('app.programmatic-draft-requests.index') }}" class="flex flex-wrap items-center gap-2">
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
                            <th class="py-2 pr-4">Title</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Mode</th>
                            <th class="py-2 pr-4">Priority</th>
                            <th class="py-2 pr-4">Tokens</th>
                            <th class="py-2 pr-4">Cost</th>
                            <th class="py-2 pr-4">Brief</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($draftRequests as $draftRequest)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-textPrimary"><a href="{{ route('app.programmatic-draft-requests.show', $draftRequest) }}" class="hover:text-primary">{{ $draftRequest->title }}</a></td>
                                <td class="py-2 pr-4 text-textSecondary">{{ str($draftRequest->status)->headline() }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ str($draftRequest->generation_mode)->headline() }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ number_format((float) $draftRequest->priority_score, 1) }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ number_format((int) $draftRequest->estimated_tokens) }}</td>
                                <td class="py-2 pr-4 text-textSecondary">€{{ number_format((float) $draftRequest->estimated_cost, 4) }}</td>
                                <td class="py-2 pr-4 text-textSecondary">@if ($draftRequest->brief)<a href="{{ route('app.content.workspace.show', $draftRequest->brief) }}" class="text-primary hover:underline">Brief</a>@else n/a @endif</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-6 text-center text-sm text-textMuted">No draft requests prepared yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $draftRequests->links() }}</div>
        </section>
    </div>
@endsection
