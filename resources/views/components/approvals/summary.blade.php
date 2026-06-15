@props(['recommendation'])

<section {{ $attributes->merge(['class' => 'rounded-lg border border-border bg-surface p-5']) }} aria-label="Approval summary">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Approval Summary</p>
            <h2 class="mt-1 text-xl font-semibold text-textPrimary">{{ data_get($recommendation, 'headline') }}</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <div class="rounded-md border border-emerald-200 bg-emerald-50 p-3">
                    <p class="text-sm font-semibold text-emerald-900">{{ data_get($recommendation, 'messages.recommend') }}</p>
                    <p class="mt-1 text-xs text-emerald-800">{{ (int) data_get($recommendation, 'recommended_count', 0) }} ready for recommended approval</p>
                </div>
                <div class="rounded-md border border-amber-200 bg-amber-50 p-3">
                    <p class="text-sm font-semibold text-amber-900">{{ data_get($recommendation, 'messages.judgment') }}</p>
                    <p class="mt-1 text-xs text-amber-800">{{ (int) data_get($recommendation, 'judgment_count', 0) }} need individual review</p>
                </div>
                <div class="rounded-md border border-rose-200 bg-rose-50 p-3">
                    <p class="text-sm font-semibold text-rose-900">{{ data_get($recommendation, 'messages.blocked') }}</p>
                    <p class="mt-1 text-xs text-rose-800">{{ (int) data_get($recommendation, 'blocked_count', 0) }} blocked before approval</p>
                </div>
            </div>
        </div>
        <form method="POST" action="{{ route('app.agentic-marketing.approvals.approve-recommended') }}">
            @csrf
            @foreach ((array) data_get($recommendation, 'recommended_run_ids', []) as $runId)
                <input type="hidden" name="run_ids[]" value="{{ $runId }}">
            @endforeach
            <button class="pl-btn-primary whitespace-nowrap" type="submit" @disabled((int) data_get($recommendation, 'recommended_count', 0) === 0)>
                <i data-lucide="check-check" class="h-4 w-4"></i>
                <span>Approve Recommended Actions</span>
            </button>
        </form>
    </div>
</section>
