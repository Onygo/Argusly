<div class="mb-6 rounded-lg border border-border bg-surface p-4">
    <div class="mb-3 flex items-center justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-textPrimary">Plan quota limits</h3>
            <p class="text-xs text-textSecondary">
                @if ($activePlan)
                    Active plan: {{ $activePlan->name }}
                @else
                    No active plan found for this organization.
                @endif
            </p>
        </div>
    </div>

    @if ($activePlan)
        <form method="POST" action="{{ route('admin.billing.plans.quota-limits.update', $activePlan) }}" class="grid gap-3 md:grid-cols-3">
            @csrf
            @foreach ($quotaSettings as $featureKey => $setting)
                <div>
                    <label class="text-xs text-textSecondary">{{ $setting['label'] }}</label>
                    <input
                        type="number"
                        name="{{ $featureKey }}"
                        min="-1"
                        class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm"
                        value="{{ old($featureKey, $setting['value']) }}"
                        required
                    >
                    <p class="mt-1 text-[11px] text-textSecondary">Use `-1` for unlimited.</p>
                    @error($featureKey)
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="md:col-span-3">
                <button class="inline-flex items-center gap-2 rounded border border-border px-3 py-2 text-sm">
                    Save quota limits for this plan
                </button>
            </div>
        </form>
    @endif
</div>

