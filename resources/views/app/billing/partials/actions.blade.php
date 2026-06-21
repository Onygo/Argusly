<div class="mb-6 rounded-lg border border-border bg-surface p-4">
    @php
        $billingSubscription = $currentSubscription ?? $activeSubscription;
        $accessLabel = $organizationAccess['label'] ?? 'Free';
        $billingUiNl = app()->getLocale() === 'nl';
        $billingIntervalLabel = fn (?string $interval): string => $billingUiNl
            ? match ((string) $interval) {
                'month' => 'maand',
                'year' => 'jaar',
                default => (string) $interval,
            }
            : (string) $interval;
        $billingMoney = fn (int|float $cents, string $currency = 'EUR'): string => number_format(((float) $cents) / 100, 2, $billingUiNl ? ',' : '.', $billingUiNl ? '.' : ',') . ' ' . $currency;
        $billingVatLabel = $billingUiNl ? 'incl. btw' : 'incl. VAT';
        $billingOneTimeLabel = $billingUiNl ? 'eenmalig' : 'one time';
        $billingPlanLabel = function ($plan): string {
            if (! $plan) {
                return 'No active subscription';
            }

            $credits = (int) ($plan->included_credits_per_interval ?: $plan->included_credits ?: 0);

            return $credits > 0
                ? trim((string) $plan->name) . ' - ' . number_format($credits) . ' credits/month'
                : trim((string) $plan->name);
        };
    @endphp

    <div class="mb-4 rounded border border-border/70 bg-surfaceSubtle/30 p-3 text-xs text-textSecondary">
        <div class="font-medium text-textPrimary">Onboarding flow</div>
        <ol class="mt-1 list-decimal space-y-1 pl-4">
            <li>Choose your plan and complete payment onboarding.</li>
            <li>Connect your first WordPress or Laravel site.</li>
        </ol>
    </div>

    <div class="mb-4 rounded border border-border p-3">
        <p class="mb-2 text-sm font-medium text-textPrimary">Billing profile</p>
        <form method="POST" action="{{ route('app.billing.profile.update') }}" class="grid gap-2 md:grid-cols-3">
            @csrf
            <input type="text" name="company_name" value="{{ old('company_name', $organization->billing_company_name ?? $organization->name) }}" placeholder="Company name" class="rounded border border-border bg-background px-2 py-2 text-sm" required>
            <input type="text" name="country_code" value="{{ old('country_code', $organization->billing_country_code ?? 'NL') }}" placeholder="Country code" class="rounded border border-border bg-background px-2 py-2 text-sm" required>
            <input type="text" name="kvk_number" value="{{ old('kvk_number', $organization->billing_kvk_number) }}" placeholder="KvK number" class="rounded border border-border bg-background px-2 py-2 text-sm">
            <input type="text" name="address_line1" value="{{ old('address_line1', $organization->billing_address_line1) }}" placeholder="Address line 1" class="rounded border border-border bg-background px-2 py-2 text-sm md:col-span-2" required>
            <input type="text" name="address_line2" value="{{ old('address_line2', $organization->billing_address_line2) }}" placeholder="Address line 2" class="rounded border border-border bg-background px-2 py-2 text-sm">
            <input type="text" name="city" value="{{ old('city', $organization->billing_city) }}" placeholder="City" class="rounded border border-border bg-background px-2 py-2 text-sm">
            <input type="text" name="postal_code" value="{{ old('postal_code', $organization->billing_postal_code) }}" placeholder="Postal code" class="rounded border border-border bg-background px-2 py-2 text-sm">
            <input type="text" name="vat_number" value="{{ old('vat_number', $organization->billing_vat_number) }}" placeholder="VAT number" class="rounded border border-border bg-background px-2 py-2 text-sm">
            <div class="md:col-span-3">
                <button class="rounded border border-border px-3 py-2 text-sm">Save billing details</button>
            </div>
        </form>
        <p class="mt-2 text-xs text-textSecondary">These details are used for invoices and backfill invoice generation.</p>
    </div>

    <div class="mb-3 rounded border border-border/70 bg-surfaceSubtle/30 p-3 text-xs text-textSecondary">
        @if ($organizationAccess['is_early_bird_active'] ?? false)
            <div class="mb-2">
                <span class="inline-flex items-center rounded-md border px-2 py-1 font-medium {{ $organizationAccess['badge_classes'] ?? 'border-border bg-background text-textSecondary' }}">{{ $accessLabel }}</span>
            </div>
            <div><strong>Commercial access:</strong> This organization has temporary Early Bird access and is not currently billed.</div>
            <div><strong>Early Bird until:</strong> {{ optional($organizationAccess['early_bird_ends_at'] ?? null)->format('Y-m-d') ?? 'n/a' }}</div>
        @elseif ($organizationAccess['is_early_bird_expired'] ?? false)
            <div class="mb-2">
                <span class="inline-flex items-center rounded-md border px-2 py-1 font-medium {{ $organizationAccess['badge_classes'] ?? 'border-border bg-background text-textSecondary' }}">{{ $accessLabel }}</span>
            </div>
        @endif
        <div><strong>Current plan:</strong> {{ $billingPlanLabel($billingSubscription?->plan) }}</div>
        <div>
            <strong>Scheduled next plan:</strong>
            @php $nextPlan = $scheduledPlanChange?->toPlan ?? $activeSubscription?->pendingPlan; @endphp
            {{ $nextPlan ? $billingPlanLabel($nextPlan) : 'None scheduled' }}
        </div>
        <div>
            <strong>Effective date:</strong>
            {{ optional($scheduledPlanChange?->effective_at ?? $activeSubscription?->current_period_end)->format('Y-m-d') ?? 'n/a' }}
        </div>
        <div><strong>Monthly price:</strong> {{ $billingSubscription ? number_format((($billingSubscription->price_cents ?? 0) / 100), 2) . ' ' . ($billingSubscription->currency ?? 'EUR') : 'n/a' }}</div>
        <div><strong>Monthly credits:</strong> {{ number_format((int) ($billingSubscription?->included_credits_per_interval ?? 0)) }}</div>
        <div><strong>Purchased credits:</strong> {{ number_format((int) ($totals['purchased_credits_available'] ?? 0)) }}</div>
        <div><strong>Total available credits:</strong> {{ number_format((int) ($totals['available'] ?? 0)) }}</div>
        <div><strong>Status:</strong> {{ $billingSubscription?->status ?? 'inactive' }}</div>
        <div><strong>Next payment:</strong> {{ optional($billingSubscription?->next_payment_at ?? $billingSubscription?->current_period_end)->format('Y-m-d') ?? 'n/a' }}</div>
        <div><strong>Interval:</strong> {{ $billingSubscription?->interval ?? 'n/a' }}</div>
        <div><strong>Renewal status:</strong> {{ $billingSubscription?->status_reason ?? 'on_schedule' }}</div>
        <div><strong>Mandate:</strong> {{ $billingSubscription?->provider_mandate_id ? 'active' : ($billingSubscription?->status === 'pending_mandate' ? 'pending' : 'n/a') }}</div>
    </div>

    @if ($billingSubscription && $billingSubscription->status === 'pending_mandate')
        <x-alert class="mb-4">
            Your payment is being processed. Your plan will be activated automatically once your payment is received.
        </x-alert>
    @elseif (! empty($pendingImmediatePlanChange))
        <div class="mb-4 rounded border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-800">
            <p class="font-medium">Upgrade payment pending</p>
            <p class="mt-1">
                You are upgrading to {{ $pendingImmediatePlanChange->toPlan?->name ?? 'the selected plan' }}.
                New plan entitlements unlock after payment confirmation.
            </p>
            @if ($pendingImmediatePlanChange->proration_amount_cents > 0)
                <p class="mt-1 text-xs">
                    Pending charge:
                    {{ number_format(($pendingImmediatePlanChange->proration_amount_cents ?? 0) / 100, 2) }}
                    {{ $pendingImmediatePlanChange->currency ?? 'EUR' }}.
                </p>
            @endif
        </div>
    @elseif ($organizationAccess['is_early_bird_active'] ?? false)
        <div class="mb-4 rounded border border-border p-3 text-sm text-textPrimary">
            Early Bird access is active.
            @if (($organizationAccess['early_bird_ends_at'] ?? null))
                <span class="text-textSecondary">Valid until {{ $organizationAccess['early_bird_ends_at']->format('Y-m-d') }}.</span>
            @endif
        </div>
    @elseif (! $billingSubscription)
        @php
            $selectedPlanId = (string) old('plan_id', (string) ($preselectedPlanId ?? optional($plans->first())->id ?? ''));
            $selectedCheckout = (array) ($planCheckoutSummaries[$selectedPlanId] ?? ($plans->first() ? ($planCheckoutSummaries[(string) $plans->first()->id] ?? []) : []));
            $selectedLineItems = (array) ($selectedCheckout['line_items'] ?? []);
        @endphp
        <div class="mb-4 rounded border border-border p-3">
            <p class="mb-2 text-sm font-medium text-textPrimary">Start subscription and mandate setup</p>
            <form method="POST" action="{{ route('app.billing.subscription.start') }}" class="grid gap-2 md:grid-cols-3">
                @csrf
                <select name="plan_id" class="rounded border border-border bg-background px-2 py-2 text-sm" required>
                    @foreach ($plans as $plan)
                        @php
                            $planCheckout = (array) ($planCheckoutSummaries[(string) $plan->id] ?? []);
                        @endphp
                        <option value="{{ $plan->id }}" @selected($selectedPlanId === (string) $plan->id)>{{ $billingPlanLabel($plan) }} ({{ $billingIntervalLabel($plan->interval) }}) - {{ $billingMoney((int) ($plan->price_cents ?? $plan->monthly_price_cents), (string) $plan->currency) }} {{ $billingVatLabel }} @if(($planCheckout['onboarding_amount_cents'] ?? 0) > 0)+ {{ $billingMoney((int) ($planCheckout['onboarding_amount_cents'] ?? 0), (string) $plan->currency) }} {{ strtolower((string) ($planCheckout['onboarding_label'] ?? 'onboarding')) }} {{ $billingOneTimeLabel }} @endif</option>
                    @endforeach
                </select>
                <input type="text" name="company_name" value="{{ old('company_name', $organization->billing_company_name ?? $organization->name) }}" placeholder="Company name" class="rounded border border-border bg-background px-2 py-2 text-sm">
                <input type="text" name="country_code" value="{{ old('country_code', $organization->billing_country_code ?? 'NL') }}" placeholder="Country code" class="rounded border border-border bg-background px-2 py-2 text-sm">
                <input type="text" name="address_line1" value="{{ old('address_line1', $organization->billing_address_line1) }}" placeholder="Address line 1" class="rounded border border-border bg-background px-2 py-2 text-sm md:col-span-2">
                <input type="text" name="city" value="{{ old('city', $organization->billing_city) }}" placeholder="City" class="rounded border border-border bg-background px-2 py-2 text-sm">
                <input type="text" name="postal_code" value="{{ old('postal_code', $organization->billing_postal_code) }}" placeholder="Postal code" class="rounded border border-border bg-background px-2 py-2 text-sm">
                <input type="text" name="vat_number" value="{{ old('vat_number', $organization->billing_vat_number) }}" placeholder="VAT number" class="rounded border border-border bg-background px-2 py-2 text-sm">
                <input type="text" name="kvk_number" value="{{ old('kvk_number', $organization->billing_kvk_number) }}" placeholder="KvK number" class="rounded border border-border bg-background px-2 py-2 text-sm">
                <div class="md:col-span-3">
                    @if ($selectedLineItems !== [])
                        <div class="mb-3 rounded border border-border/70 bg-surfaceSubtle/30 p-3 text-xs text-textSecondary">
                            <p class="font-medium text-textPrimary">Checkout summary</p>
                            <div class="mt-2 space-y-1">
                                @foreach ($selectedLineItems as $lineItem)
                                    <div class="flex items-start justify-between gap-3">
                                        <span>
                                            {{ $lineItem['label'] ?? 'Line item' }}
                                            @if(($lineItem['type'] ?? '') === 'one_time')
                                                <span class="text-textSecondary">, one time</span>
                                            @endif
                                        </span>
                                        <span class="whitespace-nowrap">{{ $billingMoney((int) ($lineItem['amount_cents'] ?? 0), (string) ($selectedCheckout['currency'] ?? 'EUR')) }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-2 flex items-start justify-between gap-3 border-t border-border/70 pt-2 text-sm font-medium text-textPrimary">
                                <span>Total due today</span>
                                <span>{{ $billingMoney((int) ($selectedCheckout['total_due_today_cents'] ?? 0), (string) ($selectedCheckout['currency'] ?? 'EUR')) }}</span>
                            </div>
                            @if(($selectedCheckout['onboarding_amount_cents'] ?? 0) > 0)
                                <p class="mt-2 text-[11px] leading-5 text-textSecondary">{{ $selectedCheckout['onboarding_label'] ?? 'Onboarding' }} is charged once during the first payment only and does not return on future billing cycles.</p>
                            @endif
                        </div>
                    @endif
                    <button class="rounded border border-border px-3 py-2 text-sm">Continue to first payment</button>
                </div>
            </form>
        </div>
    @elseif ($activeSubscription)
        <div class="mb-4 rounded border border-border p-3">
            <p class="mb-2 text-sm font-medium text-textPrimary">Change plan</p>
            <form method="POST" action="{{ route('app.billing.subscription.change-plan') }}" class="grid gap-2 md:grid-cols-3">
                @csrf
                <select name="to_plan_id" class="rounded border border-border bg-background px-2 py-2 text-sm" required>
                    @foreach ($plans as $plan)
                        @if ((string) $plan->id !== (string) $activeSubscription->plan_id)
                            <option value="{{ $plan->id }}">{{ $billingPlanLabel($plan) }} ({{ $billingIntervalLabel($plan->interval) }}) - {{ $billingMoney((int) ($plan->price_cents ?? $plan->monthly_price_cents), (string) $plan->currency) }} {{ $billingVatLabel }}</option>
                        @endif
                    @endforeach
                </select>
                <select name="timing" class="rounded border border-border bg-background px-2 py-2 text-sm" required>
                    <option value="next_period" @selected(old('timing', 'next_period') === 'next_period')>Switch next period</option>
                    <option value="immediate_prorated" @selected(old('timing') === 'immediate_prorated')>Switch now with proration</option>
                </select>
                <div class="md:col-span-3">
                    <button class="rounded border border-border px-3 py-2 text-sm">Change plan</button>
                </div>
            </form>
            <p class="mt-2 text-xs text-textSecondary">Choose when the new plan should take effect. Immediate changes may require a proration checkout.</p>
        </div>
    @endif

    @if ($activeSubscription && $sites->isEmpty())
        <x-alert class="mb-4" :icon="true">
            Your subscription is active. Connect your first site to start publishing.
            <a href="{{ route('app.sites') }}" class="ml-1 underline">Go to Sites</a>
        </x-alert>
    @endif

    <div id="buy-credit-packs" class="mb-3 flex items-center gap-2">
        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
            <i data-lucide="shopping-cart" class="h-3.5 w-3.5"></i>
        </span>
        <h2 class="text-sm font-semibold text-textPrimary">Buy Extra Credits</h2>
    </div>

    <p class="mb-3 text-xs leading-5 text-textSecondary">
        Credit packs add one-off volume on top of the monthly credits included with your active plan, so generation can continue when your credit balance runs low.
    </p>

    <div class="space-y-3">
        @forelse ($packs as $pack)
            <form method="POST" action="{{ route('app.billing.packs.purchase') }}" class="rounded border border-border p-3">
                @csrf
                <input type="hidden" name="pack_key" value="{{ $pack->key }}">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-sm font-medium text-textPrimary">{{ $pack->name }} ({{ number_format($pack->credits_amount) }} credits)</p>
                        <p class="text-xs text-textSecondary">{{ $billingMoney((int) $pack->price_cents, (string) $pack->currency) }} {{ $billingVatLabel }} ({{ $billingOneTimeLabel }})</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <select name="client_site_id" class="rounded border border-border bg-background px-2 py-1.5 text-sm" required @disabled($sites->isEmpty())>
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}" @selected(old('client_site_id') === $site->id)>{{ $site->name }}</option>
                            @endforeach
                        </select>
                        <button class="inline-flex items-center gap-1 rounded border border-border px-3 py-1.5 text-sm" @disabled($sites->isEmpty() || ! $canBuyPacks)>
                            <i data-lucide="credit-card" class="h-4 w-4"></i>
                            Buy
                        </button>
                    </div>
                </div>
            </form>
        @empty
            <p class="text-sm text-textSecondary">No active credit packs available.</p>
        @endforelse
    </div>

    @if ($sites->isEmpty())
        <p class="mt-3 text-xs text-textSecondary">Add at least one site before buying credits.</p>
    @elseif (! $canBuyPacks)
        <p class="mt-3 text-xs text-rose-700">Active subscription required before credit-pack purchases.</p>
    @endif
</div>
