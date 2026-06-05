@php
    $comparisons = $comparisons ?? $brief->draftComparisons->sortByDesc('created_at');
@endphp

<div class="space-y-2">
    @forelse ($comparisons as $comparison)
        <a class="block rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle" href="{{ route('app.content.workspace.compare.show', [$brief, $comparison]) }}">
            <div class="font-medium">{{ \Illuminate\Support\Str::headline((string) $comparison->mode) }}</div>
            <div class="text-xs text-textSecondary">
                {{ $comparison->status }} · {{ optional($comparison->created_at)->format('Y-m-d H:i') }}
            </div>
            <div class="text-xs text-textSecondary">
                Credits used {{ (int) $comparison->credits_used }} · estimate {{ (int) ($comparison->estimated_credit_cost ?? $comparison->estimated_credits) }}
            </div>
        </a>
    @empty
        <p class="text-sm text-textSecondary">No compare runs yet.</p>
    @endforelse
</div>
