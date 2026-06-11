@props([
    'result',
])

@php
    $statusTone = match ($result->status) {
        'active' => 'green',
        'ready' => 'blue',
        'partially_ready' => 'amber',
        default => 'slate',
    };
@endphp

<article {{ $attributes->merge(['class' => 'rounded-md border border-border bg-surface p-5']) }}>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-textPrimary">{{ $result->label }}</h2>
            <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $result->description }}</p>
        </div>
        <x-status-badge :status="str_replace('_', ' ', $result->status)" :color="$statusTone" />
    </div>

    <x-readiness-progress class="mt-5" :value="$result->progress" />

    @if ($result->blocking_message)
        <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $result->blocking_message }}</p>
    @endif

    <div class="mt-5">
        <x-setup-checklist :requirements="$result->requirements" />
    </div>

    @if (! empty($result->recommended_actions))
        <div class="mt-5 flex flex-wrap gap-2">
            @foreach ($result->recommended_actions as $action)
                @if ($action->route)
                    <a href="{{ $action->route }}" class="inline-flex h-9 items-center gap-2 rounded-md {{ $action->type === 'primary' ? 'bg-primary text-white hover:bg-primaryHover' : 'border border-border bg-surface text-textPrimary hover:bg-surfaceMuted' }} px-3 text-sm font-medium">
                        {{ $action->label }}
                    </a>
                @else
                    <span class="inline-flex h-9 items-center rounded-md border border-border bg-surfaceMuted px-3 text-sm font-medium text-textMuted">{{ $action->label }}</span>
                @endif
            @endforeach
        </div>
    @endif
</article>
