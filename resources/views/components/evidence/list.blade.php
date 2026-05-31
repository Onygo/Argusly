@props(['items', 'title' => 'Evidence'])

@php
    $items = collect($items ?? []);
@endphp

<div {{ $attributes->merge(['class' => 'rounded-lg border border-line bg-white p-5']) }}>
    <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-ink">{{ $title }}</h3>
        <x-ui.badge>{{ $items->count() }}</x-ui.badge>
    </div>

    @if ($items->isEmpty())
        <p class="mt-3 text-sm leading-6 text-muted">No evidence has been attached yet.</p>
    @else
        <div class="mt-4 space-y-3">
            @foreach ($items as $item)
                <div class="rounded-lg border border-line bg-panel p-4">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge variant="blue">{{ str($item->evidence_type)->replace('_', ' ')->headline() }}</x-ui.badge>
                                @if ($item->source)
                                    <x-ui.badge>{{ $item->source->name }}</x-ui.badge>
                                @endif
                                @if ($item->confidence_score !== null)
                                    <span class="text-xs text-muted">{{ $item->confidence_score }}% confidence</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm font-semibold text-ink">{{ $item->title ?: 'Untitled evidence' }}</p>
                            @if ($item->snippet)
                                <p class="mt-1 line-clamp-3 text-sm leading-6 text-muted">{{ $item->snippet }}</p>
                            @endif
                            @if ($item->url)
                                <a href="{{ $item->url }}" target="_blank" rel="noreferrer" class="mt-2 inline-flex text-xs font-semibold text-blue hover:underline">Open evidence</a>
                            @endif
                        </div>
                        <time class="shrink-0 text-xs text-muted" datetime="{{ $item->captured_at?->toIso8601String() }}">
                            {{ $item->captured_at?->format('M j, Y H:i') ?? 'No date' }}
                        </time>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
