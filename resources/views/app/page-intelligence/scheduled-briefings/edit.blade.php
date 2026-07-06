@extends('layouts.app', ['title' => 'Edit Scheduled Briefing'])

@section('pageHeader')
    <x-page-header title="Edit Scheduled Briefing">
        <x-slot:description>{{ $workspace->display_name }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('app.page-intelligence.scheduled-briefings.index', ['workspace' => $workspace->id]) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Scheduled Briefings</a>
    <a href="{{ route('app.page-intelligence.reports.index', ['workspace' => $workspace->id]) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Reports</a>
@endsection

@section('content')
    <div class="space-y-6">
        <section class="rounded-lg border border-border bg-surface p-4">
            <form method="POST" action="{{ route('app.page-intelligence.scheduled-briefings.update', $briefing) }}" class="space-y-4">
                @csrf
                @method('PUT')
                @include('app.page-intelligence.scheduled-briefings._form')
                <div class="flex justify-end">
                    <button class="rounded-md border border-textPrimary bg-textPrimary px-3 py-2 text-sm text-white hover:opacity-90">Save schedule</button>
                </div>
            </form>
            @if ($errors->any())
                <div class="mt-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    {{ $errors->first() }}
                </div>
            @endif
        </section>

        <section class="rounded-lg border border-border bg-surface p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Delivery History</h2>
                    <p class="mt-1 text-sm text-textSecondary">{{ $briefing->deliveries->count() }} recent delivery record{{ $briefing->deliveries->count() === 1 ? '' : 's' }}</p>
                </div>
                <span class="rounded border border-border px-2 py-1 text-xs text-textSecondary">{{ str(data_get($briefing->delivery_state_json, 'status', 'not delivered'))->headline() }}</span>
            </div>

            <div class="mt-4 divide-y divide-border">
                @forelse ($briefing->deliveries as $delivery)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <p class="text-sm font-medium text-textPrimary">
                                @if ($delivery->report)
                                    <a href="{{ route('app.page-intelligence.reports.show', $delivery->report) }}" class="hover:underline">{{ $delivery->report->title }}</a>
                                @else
                                    Report unavailable
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-textSecondary">
                                {{ $delivery->recipientUser?->name ?: $delivery->recipient_email ?: 'Recipient placeholder' }}
                                · {{ str($delivery->channel)->headline() }}
                                · {{ str($delivery->status)->headline() }}
                            </p>
                            @if ($delivery->error)
                                <p class="mt-1 text-xs text-textSecondary">{{ $delivery->error }}</p>
                            @endif
                        </div>
                        <span class="text-xs text-textSecondary">{{ $delivery->created_at?->format('M j, Y H:i') }}</span>
                    </div>
                @empty
                    <p class="text-sm text-textSecondary">No delivery records yet.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
