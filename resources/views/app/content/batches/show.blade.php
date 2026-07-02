@extends('layouts.app', ['title' => 'Content Batch'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Content batch</x-slot:title>
        <x-slot:description>Main keyword: {{ $batch->main_keyword }} · Status: {{ $batch->status }} · Progress: {{ $batch->items_done }} / {{ $batch->items_total }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('app.content.index') }}" class="rounded border border-border px-3 py-2 text-sm">Back to content</a>
            @if (in_array((string) $batch->status, ['draft', 'running', 'failed', 'partially_completed'], true))
                <form method="POST" action="{{ route('app.content.batches.start', $batch) }}">
                    @csrf
                    <button class="rounded border border-border px-3 py-2 text-sm">Start batch</button>
                </form>
            @endif
            @if (! in_array((string) $batch->status, ['completed', 'failed', 'canceled'], true))
                <form method="POST" action="{{ route('app.content.batches.cancel', $batch) }}">
                    @csrf
                    <button class="rounded border border-border px-3 py-2 text-sm">Cancel batch</button>
                </form>
            @endif
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->has('batch'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('batch') }}</div>
    @endif

    @php
        $progress = $batch->items_total > 0 ? (int) round(($batch->items_done / $batch->items_total) * 100) : 0;
    @endphp
    <div class="mb-4 rounded-lg border border-border bg-surface p-4">
        <div class="mb-2 flex items-center justify-between text-sm">
            <span class="text-textSecondary">Progress</span>
            <span class="text-textPrimary">{{ $progress }}%</span>
        </div>
        <div class="h-2 rounded bg-surfaceSubtle">
            <div class="h-2 rounded bg-primary" style="width: {{ max(0, min(100, $progress)) }}%"></div>
        </div>
        <div class="mt-3 grid gap-3 text-sm md:grid-cols-4">
            <div class="rounded border border-border bg-background p-3">
                <p class="text-xs text-textSecondary">Items total</p>
                <p class="font-semibold text-textPrimary">{{ $batch->items_total }}</p>
            </div>
            <div class="rounded border border-border bg-background p-3">
                <p class="text-xs text-textSecondary">Items done</p>
                <p class="font-semibold text-textPrimary">{{ $batch->items_done }}</p>
            </div>
            <div class="rounded border border-border bg-background p-3">
                <p class="text-xs text-textSecondary">Credits estimated</p>
                <p class="font-semibold text-textPrimary">{{ $batch->credits_estimated }}</p>
            </div>
            <div class="rounded border border-border bg-background p-3">
                <p class="text-xs text-textSecondary">Credits used</p>
                <p class="font-semibold text-textPrimary">{{ $batch->credits_used }}</p>
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-border bg-surface p-4">
        <x-data-table label="Batch items" description="Generated batch items with keyword angle, intent, status, linked brief or draft, and retry action." density="compact" class="border-0 shadow-none" table-class="min-w-[900px] text-sm">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>#</x-data-table.cell>
                    <x-data-table.cell heading>Subkeyword</x-data-table.cell>
                    <x-data-table.cell heading>Angle</x-data-table.cell>
                    <x-data-table.cell heading>Intent</x-data-table.cell>
                    <x-data-table.cell heading>Status</x-data-table.cell>
                    <x-data-table.cell heading>Brief</x-data-table.cell>
                    <x-data-table.cell heading>Draft</x-data-table.cell>
                    <x-data-table.cell heading>Action</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody class="divide-y divide-border">
                @forelse ($items as $item)
                    <x-data-table.row>
                        <x-data-table.cell label="#">{{ $item->sort_order }}</x-data-table.cell>
                        <x-data-table.cell label="Subkeyword" class="text-textPrimary">{{ $item->subkeyword }}</x-data-table.cell>
                        <x-data-table.cell label="Angle" class="text-textSecondary">{{ $item->angle ?: '-' }}</x-data-table.cell>
                        <x-data-table.cell label="Intent" class="text-textSecondary">{{ $item->intent ?: '-' }}</x-data-table.cell>
                        <x-data-table.cell label="Status">
                            <x-data-table.badge :label="$item->status" />
                        </x-data-table.cell>
                        <x-data-table.cell label="Brief">
                            @if ($item->brief_id)
                                <a href="{{ route('app.content.workspace.show', $item->brief_id) }}" class="text-link hover:text-linkHover underline">Open content workspace</a>
                            @else
                                -
                            @endif
                        </x-data-table.cell>
                        <x-data-table.cell label="Draft">
                            @if ($item->draft?->content_id)
                                <a href="{{ route('app.content.show', $item->draft->content_id) }}" class="text-link hover:text-linkHover underline">Open draft</a>
                            @elseif ($item->draft_id)
                                <a href="{{ route('app.drafts.show', $item->draft_id) }}" class="text-link hover:text-linkHover underline">Open draft</a>
                            @else
                                -
                            @endif
                        </x-data-table.cell>
                        <x-data-table.cell label="Action">
                            @if ($item->status === 'failed')
                                <form method="POST" action="{{ route('app.content.batches.items.retry', [$batch, $item]) }}">
                                    @csrf
                                    <button class="rounded border border-border px-2 py-1 text-xs">Retry</button>
                                </form>
                            @else
                                -
                            @endif
                        </x-data-table.cell>
                    </x-data-table.row>
                    @if (!empty($item->error_message))
                        <x-data-table.row>
                            <x-data-table.cell colspan="8" class="pb-3 text-xs text-rose-700">{{ $item->error_message }}</x-data-table.cell>
                        </x-data-table.row>
                    @endif
                @empty
                    <x-data-table.empty colspan="8" title="No batch items found" />
                @endforelse
            </tbody>
        </x-data-table>
    </div>
@endsection
