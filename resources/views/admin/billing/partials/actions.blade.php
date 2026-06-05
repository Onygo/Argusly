<div class="mb-6 rounded-lg border border-border bg-surface p-4">
    <div class="mb-4 rounded border border-border p-3">
        <div class="mb-2 text-sm font-semibold text-textPrimary">Manual billing actions</div>
        <div class="flex flex-wrap items-center gap-2">
            @if (! ($organizationAccess['is_early_bird_active'] ?? false))
                <form method="POST" action="{{ route('admin.organizations.billing.mandate-recheck', $organization) }}">
                    @csrf
                    <button class="inline-flex items-center gap-2 rounded border border-border px-3 py-2 text-xs">Trigger mandate recheck</button>
                </form>
                <form method="POST" action="{{ route('admin.organizations.billing.renewal-retry', $organization) }}">
                    @csrf
                    <button class="inline-flex items-center gap-2 rounded border border-border px-3 py-2 text-xs">Trigger renewal retry</button>
                </form>
                <form method="POST" action="{{ route('admin.organizations.billing.subscription.force-cancel', $organization) }}" onsubmit="return confirm('Force-cancel the current subscription and pending checkout intents?');">
                    @csrf
                    <button class="inline-flex items-center gap-2 rounded border border-border px-3 py-2 text-xs text-rose-700">Force cancel subscription</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.organizations.hold', $organization) }}">
                @csrf
                <button class="inline-flex items-center gap-2 rounded border border-border px-3 py-2 text-xs">Put organization on hold</button>
            </form>
        </div>

        @if ($organizationAccess['is_early_bird_active'] ?? false)
            <p class="mt-3 text-xs text-textSecondary">Subscription recovery actions are hidden while Early Bird temporarily bypasses billing enforcement.</p>
        @endif

        <form method="POST" action="{{ route('admin.organizations.billing.subscription.grant-monthly-credits', $organization) }}" class="mt-4 grid gap-3 rounded border border-border p-3 md:grid-cols-4" onsubmit="return confirm('Grant missing monthly plan credits for this period? This action is idempotent and auditable.');">
            @csrf
            <div>
                <label class="text-xs text-textSecondary">Billing period (YYYY-MM)</label>
                <input type="text" name="period" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm" value="{{ old('period', now()->format('Y-m')) }}" placeholder="2026-03">
            </div>
            <div class="flex items-center gap-2 pt-6">
                <input id="dry_run_monthly_recovery" type="checkbox" name="dry_run" value="1" class="rounded border-border" @checked(old('dry_run'))>
                <label for="dry_run_monthly_recovery" class="text-xs text-textSecondary">Dry run (no writes)</label>
            </div>
            <div class="flex items-center gap-2 pt-6">
                <input id="confirm_monthly_recovery" type="checkbox" name="confirm_recovery" value="1" class="rounded border-border" @checked(old('confirm_recovery'))>
                <label for="confirm_monthly_recovery" class="text-xs text-textSecondary">Confirm recovery action</label>
            </div>
            <div class="pt-5">
                <button class="inline-flex items-center gap-2 rounded border border-border px-3 py-2 text-xs">
                    <i data-lucide="rotate-cw" class="h-3.5 w-3.5"></i>
                    Grant monthly credits now
                </button>
            </div>
            @error('period')
                <p class="md:col-span-4 text-xs text-rose-700">{{ $message }}</p>
            @enderror
            @error('billing')
                <p class="md:col-span-4 text-xs text-rose-700">{{ $message }}</p>
            @enderror
        </form>
    </div>

    <div class="mb-3 flex items-center gap-2">
        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
            <i data-lucide="gift" class="h-3.5 w-3.5"></i>
        </span>
        <h3 class="text-sm font-semibold text-textPrimary">Grant Free Credits</h3>
    </div>

    <form method="POST" action="{{ route('admin.organizations.billing.grant-credits', $organization) }}" class="grid gap-3 md:grid-cols-4">
        @csrf
        <div>
            <label class="text-xs text-textSecondary">Site</label>
            <select name="client_site_id" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm" required @disabled($sites->isEmpty())>
                @forelse ($sites as $site)
                    <option value="{{ $site->id }}" @selected(old('client_site_id') === $site->id)>{{ $site->name }}</option>
                @empty
                    <option value="">No sites available</option>
                @endforelse
            </select>
            @error('client_site_id')
                <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label class="text-xs text-textSecondary">Amount</label>
            <input type="number" name="amount" min="1" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm" placeholder="100" value="{{ old('amount') }}" required>
            @error('amount')
                <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
            @enderror
        </div>
        <div class="md:col-span-2">
            <label class="text-xs text-textSecondary">Note</label>
            <input type="text" name="note" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm" placeholder="Reason for free credits" value="{{ old('note') }}">
            @error('note')
                <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
            @enderror
        </div>
        <div class="md:col-span-4">
            <button class="inline-flex items-center gap-2 rounded border border-border px-3 py-2 text-sm" @disabled($sites->isEmpty())>
                <i data-lucide="plus-circle" class="h-4 w-4"></i>
                Grant Credits
            </button>
        </div>
    </form>

    @if ($sites->isEmpty())
        <p class="mt-2 text-xs text-textSecondary">This organization has no sites, so credits cannot be granted yet.</p>
    @endif
</div>
