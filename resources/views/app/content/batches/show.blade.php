@extends('layouts.app', ['title' => 'Content Batch'])

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Content batch</h1>
            <p class="mt-1 text-textSecondary">
                Main keyword: <strong>{{ $batch->main_keyword }}</strong>
                · Status: <span class="pl-badge">{{ $batch->status }}</span>
                · Progress: {{ $batch->items_done }} / {{ $batch->items_total }}
            </p>
        </div>
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

    <div class="rounded-lg border border-border bg-surface p-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-textSecondary">
                    <th class="pb-2 font-medium">#</th>
                    <th class="pb-2 font-medium">Subkeyword</th>
                    <th class="pb-2 font-medium">Angle</th>
                    <th class="pb-2 font-medium">Intent</th>
                    <th class="pb-2 font-medium">Status</th>
                    <th class="pb-2 font-medium">Brief</th>
                    <th class="pb-2 font-medium">Draft</th>
                    <th class="pb-2 font-medium">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($items as $item)
                    <tr>
                        <td class="py-3">{{ $item->sort_order }}</td>
                        <td class="py-3 text-textPrimary">{{ $item->subkeyword }}</td>
                        <td class="py-3 text-textSecondary">{{ $item->angle ?: '-' }}</td>
                        <td class="py-3 text-textSecondary">{{ $item->intent ?: '-' }}</td>
                        <td class="py-3"><span class="pl-badge">{{ $item->status }}</span></td>
                        <td class="py-3">
                            @if ($item->brief_id)
                                <a href="{{ route('app.content.workspace.show', $item->brief_id) }}" class="text-link hover:text-linkHover underline">Open content workspace</a>
                            @else
                                -
                            @endif
                        </td>
                        <td class="py-3">
                            @if ($item->draft?->content_id)
                                <a href="{{ route('app.content.show', $item->draft->content_id) }}" class="text-link hover:text-linkHover underline">Open draft</a>
                            @elseif ($item->draft_id)
                                <a href="{{ route('app.drafts.show', $item->draft_id) }}" class="text-link hover:text-linkHover underline">Open draft</a>
                            @else
                                -
                            @endif
                        </td>
                        <td class="py-3">
                            @if ($item->status === 'failed')
                                <form method="POST" action="{{ route('app.content.batches.items.retry', [$batch, $item]) }}">
                                    @csrf
                                    <button class="rounded border border-border px-2 py-1 text-xs">Retry</button>
                                </form>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                    @if (!empty($item->error_message))
                        <tr>
                            <td colspan="8" class="pb-3 text-xs text-rose-700">{{ $item->error_message }}</td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td class="py-6 text-center text-textSecondary" colspan="8">No batch items found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
