@extends('layouts.app', ['title' => 'Programmatic Draft Requests'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Programmatic Draft Requests</x-slot:title>
        <x-slot:description>Controlled draft generation requests prepared from converted programmatic briefs.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
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

        <x-data-table label="Programmatic draft requests" description="Draft generation requests with status, mode, priority, token estimate, cost, and brief link." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Title</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Mode</x-data-table.cell>
                        <x-data-table.cell heading>Priority</x-data-table.cell>
                        <x-data-table.cell heading>Tokens</x-data-table.cell>
                        <x-data-table.cell heading>Cost</x-data-table.cell>
                        <x-data-table.cell heading>Brief</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                        @forelse ($draftRequests as $draftRequest)
                            <x-data-table.row>
                                <x-data-table.cell label="Title" class="font-medium text-textPrimary"><a href="{{ route('app.programmatic-draft-requests.show', $draftRequest) }}" class="hover:text-primary">{{ $draftRequest->title }}</a></x-data-table.cell>
                                <x-data-table.cell label="Status">
                                    <x-data-table.badge :label="str($draftRequest->status)->headline()" />
                                </x-data-table.cell>
                                <x-data-table.cell label="Mode" class="text-textSecondary">{{ str($draftRequest->generation_mode)->headline() }}</x-data-table.cell>
                                <x-data-table.cell label="Priority" class="text-textSecondary">{{ number_format((float) $draftRequest->priority_score, 1) }}</x-data-table.cell>
                                <x-data-table.cell label="Tokens" class="text-textSecondary">{{ number_format((int) $draftRequest->estimated_tokens) }}</x-data-table.cell>
                                <x-data-table.cell label="Cost" class="text-textSecondary">€{{ number_format((float) $draftRequest->estimated_cost, 4) }}</x-data-table.cell>
                                <x-data-table.cell label="Brief" class="text-textSecondary">@if ($draftRequest->brief)<a href="{{ route('app.content.workspace.show', $draftRequest->brief) }}" class="text-primary hover:underline">Brief</a>@else n/a @endif</x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="7" title="No draft requests prepared yet" />
                        @endforelse
                </tbody>
            <x-slot:pagination>{{ $draftRequests->links() }}</x-slot:pagination>
        </x-data-table>
    </div>
@endsection
