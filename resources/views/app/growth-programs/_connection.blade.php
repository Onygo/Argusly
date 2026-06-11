@php
    $subject = $subject ?? null;
    $workspaceId = (string) ($workspaceId ?? '');
    $createRoute = $createRoute ?? null;
    $attachRoute = $attachRoute ?? null;

    $linkedGrowthAssets = $subject
        ? \App\Models\GrowthAsset::query()
            ->with('program')
            ->where('assetable_type', $subject->getMorphClass())
            ->where('assetable_id', (string) $subject->getKey())
            ->get()
        : collect();

    $linkedGrowthPrograms = $linkedGrowthAssets
        ->pluck('program')
        ->filter()
        ->unique('id')
        ->values();

    $availableGrowthPrograms = $workspaceId !== ''
        ? \App\Models\GrowthProgram::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
        : collect();
@endphp

<div class="rounded-md border border-border bg-surface p-5">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-textPrimary">Growth Program</h2>
            <p class="mt-1 text-sm text-textSecondary">Traceability for this workflow.</p>
        </div>
        @if ($createRoute)
            <form method="POST" action="{{ $createRoute }}">
                @csrf
                <button class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surfaceSubtle px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                    <i data-lucide="network" class="h-4 w-4"></i>
                    Create
                </button>
            </form>
        @endif
    </div>

    <div class="mt-4 space-y-2">
        @forelse ($linkedGrowthPrograms as $program)
            @php($stage = $program->status instanceof \App\Enums\GrowthProgramStatus ? $program->status : \App\Enums\GrowthProgramStatus::tryFrom((string) $program->status))
            <a href="{{ route('app.growth-programs.show', $program) }}" class="flex items-center justify-between rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-sm hover:bg-surfaceMuted">
                <span class="font-medium text-textPrimary">{{ $program->name }}</span>
                <span class="text-xs text-textSecondary">{{ $stage?->label() ?? $program->status }}</span>
            </a>
        @empty
            <p class="rounded-md border border-dashed border-border bg-surfaceSubtle px-3 py-2 text-sm text-textMuted">No growth program linked yet.</p>
        @endforelse
    </div>

    @if ($attachRoute && $availableGrowthPrograms->isNotEmpty())
        <form method="POST" action="{{ $attachRoute }}" class="mt-4 flex flex-col gap-2 sm:flex-row">
            @csrf
            <select name="growth_program_id" class="min-h-9 flex-1 rounded-md border border-border bg-surface px-3 text-sm text-textPrimary">
                @foreach ($availableGrowthPrograms as $program)
                    <option value="{{ $program->id }}">{{ $program->name }}</option>
                @endforeach
            </select>
            <button class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                Attach
            </button>
        </form>
    @endif
</div>
