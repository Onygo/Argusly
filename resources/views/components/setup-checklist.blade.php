@props([
    'requirements' => [],
])

<div {{ $attributes->merge(['class' => 'space-y-2']) }}>
    @forelse ($requirements as $requirement)
        <div class="flex items-start gap-3 rounded-md border border-border bg-background p-3">
            <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full {{ $requirement->completed ? 'bg-successSoft text-success' : 'bg-amber-50 text-amber-700' }}">
                <i data-lucide="{{ $requirement->completed ? 'check' : 'circle-alert' }}" class="h-3.5 w-3.5"></i>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-textPrimary">{{ $requirement->label }}</p>
                <p class="mt-0.5 text-xs leading-5 text-textSecondary">{{ $requirement->description }}</p>
                @if (! $requirement->completed && $requirement->action_route)
                    <a href="{{ $requirement->action_route }}" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                        {{ $requirement->action_label ?: 'Open setup' }}
                        <i data-lucide="arrow-up-right" class="h-3 w-3"></i>
                    </a>
                @endif
            </div>
        </div>
    @empty
        <p class="text-sm text-textMuted">No setup requirements are registered for this module.</p>
    @endforelse
</div>
