<div class="mt-6 space-y-6">
    <div class="rounded-lg border border-border bg-surface p-5">
        <div class="mb-4">
            <h2 class="text-sm font-semibold text-textPrimary">Plan catalog</h2>
            <p class="mt-1 text-xs text-textSecondary">Manage public pricing plans and per-plan feature rows from the existing billing system. Use `is_active` and `is_public` instead of deleting plans.</p>
        </div>

        <form method="POST" action="{{ route('admin.billing.plans.store') }}" class="grid gap-3 md:grid-cols-3">
            @csrf
            <input type="text" name="name" value="{{ old('name') }}" placeholder="Plan name" class="rounded border border-border px-3 py-2 text-sm" required>
            <input type="text" name="slug" value="{{ old('slug') }}" placeholder="plan-slug (display)" class="rounded border border-border px-3 py-2 text-sm" required>
            <input type="text" name="internal_code" value="{{ old('internal_code') }}" placeholder="internal_code (billing)" class="rounded border border-border px-3 py-2 text-sm">
            <select name="billing_type" class="rounded border border-border px-3 py-2 text-sm">
                <option value="fixed" @selected(old('billing_type', 'fixed') === 'fixed')>fixed</option>
                <option value="custom" @selected(old('billing_type') === 'custom')>custom</option>
            </select>
            <input type="text" name="billing_provider" value="{{ old('billing_provider') }}" placeholder="Billing provider (e.g. mollie)" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="billing_provider_plan_key" value="{{ old('billing_provider_plan_key') }}" placeholder="Provider plan key" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="description" value="{{ old('description') }}" placeholder="Short description" class="rounded border border-border px-3 py-2 text-sm md:col-span-3">
            <input type="number" name="price_monthly_cents" value="{{ old('price_monthly_cents') }}" placeholder="Monthly price (cents)" class="rounded border border-border px-3 py-2 text-sm">
            <input type="number" name="price_yearly_cents" value="{{ old('price_yearly_cents') }}" placeholder="Yearly price (cents)" class="rounded border border-border px-3 py-2 text-sm">
            <input type="number" name="sort_order" value="{{ old('sort_order', 0) }}" placeholder="Sort order" class="rounded border border-border px-3 py-2 text-sm" required>
            <input type="number" name="included_credits" value="{{ old('included_credits', 0) }}" placeholder="Included credits" class="rounded border border-border px-3 py-2 text-sm">
            <input type="number" name="seat_limit" value="{{ old('seat_limit', 0) }}" placeholder="Seat limit" class="rounded border border-border px-3 py-2 text-sm">
            <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="has_required_onboarding" value="1" @checked(old('has_required_onboarding'))> Requires onboarding</label>
            <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="onboarding_is_visible_public" value="1" @checked(old('onboarding_is_visible_public', true))> Show on public pricing</label>
            <select name="onboarding_display_mode" class="rounded border border-border px-3 py-2 text-sm">
                <option value="">Display mode</option>
                <option value="guided_onboarding" @selected(old('onboarding_display_mode') === 'guided_onboarding')>Guided Onboarding</option>
                <option value="launch_setup" @selected(old('onboarding_display_mode') === 'launch_setup')>Launch Setup</option>
                <option value="implementation_onboarding" @selected(old('onboarding_display_mode') === 'implementation_onboarding')>Implementation Onboarding</option>
                <option value="custom" @selected(old('onboarding_display_mode') === 'custom')>Custom</option>
            </select>
            <input type="text" name="onboarding_label" value="{{ old('onboarding_label') }}" placeholder="Onboarding label" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="onboarding_checkout_label" value="{{ old('onboarding_checkout_label') }}" placeholder="Checkout label override" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="onboarding_receipt_label" value="{{ old('onboarding_receipt_label') }}" placeholder="Receipt label override" class="rounded border border-border px-3 py-2 text-sm">
            <input type="number" name="onboarding_fee_cents" value="{{ old('onboarding_fee_cents') }}" placeholder="Onboarding fee (cents)" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="onboarding_fee_currency" value="{{ old('onboarding_fee_currency', 'EUR') }}" placeholder="Onboarding currency" class="rounded border border-border px-3 py-2 text-sm">
            <input type="number" name="onboarding_sort_order" value="{{ old('onboarding_sort_order', 0) }}" placeholder="Onboarding sort order" class="rounded border border-border px-3 py-2 text-sm">
            <input type="datetime-local" name="onboarding_effective_from" value="{{ old('onboarding_effective_from') }}" class="rounded border border-border px-3 py-2 text-sm">
            <textarea name="onboarding_description" rows="3" placeholder="Onboarding description" class="rounded border border-border px-3 py-2 text-sm md:col-span-3">{{ old('onboarding_description') }}</textarea>
            <textarea name="onboarding_internal_notes" rows="2" placeholder="Internal onboarding notes" class="rounded border border-border px-3 py-2 text-sm md:col-span-3">{{ old('onboarding_internal_notes') }}</textarea>
            <input type="text" name="badge" value="{{ old('badge') }}" placeholder="Badge" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="cta_label" value="{{ old('cta_label') }}" placeholder="CTA label" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="cta_url" value="{{ old('cta_url') }}" placeholder="CTA URL or /path" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="currency" value="{{ old('currency', 'EUR') }}" placeholder="Currency" class="rounded border border-border px-3 py-2 text-sm">
            <div class="text-xs text-textSecondary md:col-span-3">
                <p>Shown on public pricing and checkout.</p>
                <p>One time fee charged during initial purchase only.</p>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> Active</label>
            <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="is_public" value="1" @checked(old('is_public', true))> Public</label>
            <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured'))> Featured</label>
            <div class="md:col-span-3">
                <button class="inline-flex items-center rounded border border-border px-3 py-1.5 text-xs">Create plan</button>
            </div>
        </form>
    </div>

    <div class="space-y-4">
        @foreach($plans as $plan)
            <section class="rounded-lg border border-border bg-surface p-5">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-textPrimary">{{ $plan->name }}</h3>
                        <p class="mt-1 text-xs text-textSecondary">
                            slug: <span class="font-mono">{{ $plan->slug }}</span>
                            · billing: {{ $plan->billing_type }}
                            · public: {{ $plan->is_public ? 'yes' : 'no' }}
                            · featured: {{ $plan->is_featured ? 'yes' : 'no' }}
                            · onboarding: {{ $plan->has_required_onboarding ? 'yes' : 'no' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-[11px] text-textSecondary">
                        <span class="rounded border border-border px-2 py-0.5">sort {{ $plan->sort_order }}</span>
                        @if ($plan->billing_type === 'custom')
                            <span class="rounded border border-border px-2 py-0.5">custom</span>
                        @endif
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.billing.plans.update', $plan) }}" class="grid gap-3 md:grid-cols-3">
                    @csrf
                    <input type="text" name="name" value="{{ $plan->name }}" placeholder="Plan name" class="rounded border border-border px-3 py-2 text-sm" required>
                    <input type="text" name="slug" value="{{ $plan->slug }}" placeholder="plan-slug (display)" class="rounded border border-border px-3 py-2 text-sm" required>
                    <input type="text" name="internal_code" value="{{ $plan->internal_code }}" placeholder="internal_code (billing)" class="rounded border border-border px-3 py-2 text-sm">
                    <select name="billing_type" class="rounded border border-border px-3 py-2 text-sm">
                        <option value="fixed" @selected($plan->billing_type === 'fixed')>fixed</option>
                        <option value="custom" @selected($plan->billing_type === 'custom')>custom</option>
                    </select>
                    <input type="text" name="billing_provider" value="{{ $plan->billing_provider }}" placeholder="Billing provider (e.g. mollie)" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="text" name="billing_provider_plan_key" value="{{ $plan->billing_provider_plan_key }}" placeholder="Provider plan key" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="text" name="description" value="{{ $plan->description_short }}" placeholder="Short description" class="rounded border border-border px-3 py-2 text-sm md:col-span-3">
                    <input type="number" name="price_monthly_cents" value="{{ $plan->price_monthly_cents }}" placeholder="Monthly price (cents)" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="number" name="price_yearly_cents" value="{{ $plan->price_yearly_cents }}" placeholder="Yearly price (cents)" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="number" name="sort_order" value="{{ $plan->sort_order }}" placeholder="Sort order" class="rounded border border-border px-3 py-2 text-sm" required>
                    <input type="number" name="included_credits" value="{{ $plan->included_credits_per_interval ?: $plan->included_credits }}" placeholder="Included credits" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="number" name="seat_limit" value="{{ $plan->seat_limit }}" placeholder="Seat limit" class="rounded border border-border px-3 py-2 text-sm">
                    <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="has_required_onboarding" value="1" @checked($plan->has_required_onboarding)> Requires onboarding</label>
                    <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="onboarding_is_visible_public" value="1" @checked($plan->onboarding_is_visible_public)> Show on public pricing</label>
                    <select name="onboarding_display_mode" class="rounded border border-border px-3 py-2 text-sm">
                        <option value="">Display mode</option>
                        <option value="guided_onboarding" @selected($plan->onboarding_display_mode === 'guided_onboarding')>Guided Onboarding</option>
                        <option value="launch_setup" @selected($plan->onboarding_display_mode === 'launch_setup')>Launch Setup</option>
                        <option value="implementation_onboarding" @selected($plan->onboarding_display_mode === 'implementation_onboarding')>Implementation Onboarding</option>
                        <option value="custom" @selected($plan->onboarding_display_mode === 'custom')>Custom</option>
                    </select>
                    <input type="text" name="onboarding_label" value="{{ $plan->onboarding_label }}" placeholder="Onboarding label" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="text" name="onboarding_checkout_label" value="{{ $plan->onboarding_checkout_label }}" placeholder="Checkout label override" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="text" name="onboarding_receipt_label" value="{{ $plan->onboarding_receipt_label }}" placeholder="Receipt label override" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="number" name="onboarding_fee_cents" value="{{ $plan->onboarding_fee_cents }}" placeholder="Onboarding fee (cents)" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="text" name="onboarding_fee_currency" value="{{ $plan->onboarding_fee_currency ?: 'EUR' }}" placeholder="Onboarding currency" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="number" name="onboarding_sort_order" value="{{ $plan->onboarding_sort_order ?? 0 }}" placeholder="Onboarding sort order" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="datetime-local" name="onboarding_effective_from" value="{{ optional($plan->onboarding_effective_from)->format('Y-m-d\\TH:i') }}" class="rounded border border-border px-3 py-2 text-sm">
                    <textarea name="onboarding_description" rows="3" placeholder="Onboarding description" class="rounded border border-border px-3 py-2 text-sm md:col-span-3">{{ $plan->onboarding_description }}</textarea>
                    <textarea name="onboarding_internal_notes" rows="2" placeholder="Internal onboarding notes" class="rounded border border-border px-3 py-2 text-sm md:col-span-3">{{ $plan->onboarding_internal_notes }}</textarea>
                    <input type="text" name="badge" value="{{ $plan->badge }}" placeholder="Badge" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="text" name="cta_label" value="{{ $plan->cta_label }}" placeholder="CTA label" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="text" name="cta_url" value="{{ $plan->cta_url }}" placeholder="CTA URL or /path" class="rounded border border-border px-3 py-2 text-sm">
                    <input type="text" name="currency" value="{{ $plan->currency ?: 'EUR' }}" placeholder="Currency" class="rounded border border-border px-3 py-2 text-sm">
                    <div class="text-xs text-textSecondary md:col-span-3">
                        <p>Shown on public pricing and checkout.</p>
                        <p>One time fee charged during initial purchase only.</p>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="is_active" value="1" @checked($plan->is_active)> Active</label>
                    <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="is_public" value="1" @checked($plan->is_public)> Public</label>
                    <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="is_featured" value="1" @checked($plan->is_featured)> Featured</label>
                    <div class="md:col-span-3">
                        <button class="inline-flex items-center rounded border border-border px-3 py-1.5 text-xs">Save plan</button>
                    </div>
                </form>

                <div class="mt-5 rounded-lg border border-border bg-background p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h4 class="text-sm font-semibold text-textPrimary">Features</h4>
                        <span class="text-xs text-textSecondary">{{ $plan->features->count() }} items</span>
                    </div>

                    <div class="mt-3 space-y-3">
                        @foreach($plan->features as $feature)
                            <div class="rounded border border-border bg-surface p-3">
                                <form method="POST" action="{{ route('admin.billing.plans.features.update', [$plan, $feature]) }}" class="grid gap-2 md:grid-cols-6">
                                    @csrf
                                    <input type="text" name="feature_key" value="{{ $feature->feature_key }}" class="rounded border border-border px-3 py-2 text-sm" placeholder="feature_key" required>
                                    <input type="text" name="label" value="{{ $feature->label }}" class="rounded border border-border px-3 py-2 text-sm" placeholder="Label" required>
                                    <input type="text" name="feature_group" value="{{ $feature->feature_group }}" class="rounded border border-border px-3 py-2 text-sm" placeholder="Group">
                                    <input type="number" name="sort_order" value="{{ $feature->sort_order }}" class="rounded border border-border px-3 py-2 text-sm" placeholder="Sort" required>
                                    <select name="value_type" class="rounded border border-border px-3 py-2 text-sm">
                                        @foreach(['bool', 'int', 'string', 'json'] as $type)
                                            <option value="{{ $type }}" @selected($feature->value_type === $type)>{{ $type }}</option>
                                        @endforeach
                                    </select>
                                    <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="is_highlight" value="1" @checked($feature->is_highlight)> Highlight</label>
                                    <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="value_bool" value="1" @checked((bool) $feature->value_bool)> Bool true</label>
                                    <input type="number" name="value_int" value="{{ $feature->value_int }}" class="rounded border border-border px-3 py-2 text-sm" placeholder="Int value">
                                    <input type="text" name="value_string" value="{{ $feature->value_string }}" class="rounded border border-border px-3 py-2 text-sm md:col-span-2" placeholder="String value">
                                    <input type="text" name="value_json" value="{{ $feature->value_json ? json_encode($feature->value_json) : '' }}" class="rounded border border-border px-3 py-2 text-sm md:col-span-2" placeholder='JSON value'>
                                    <div class="md:col-span-6">
                                        <button class="inline-flex items-center rounded border border-border px-3 py-1.5 text-xs">Save feature</button>
                                    </div>
                                </form>
                                <form method="POST" action="{{ route('admin.billing.plans.features.destroy', [$plan, $feature]) }}" class="mt-2" onsubmit="return confirm('Remove this feature from the plan?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="inline-flex items-center rounded border border-rose-300 px-3 py-1.5 text-xs text-rose-700">Delete feature</button>
                                </form>
                            </div>
                        @endforeach
                    </div>

                    <form method="POST" action="{{ route('admin.billing.plans.features.store', $plan) }}" class="mt-4 grid gap-2 rounded border border-dashed border-border p-3 md:grid-cols-6">
                        @csrf
                        <input type="text" name="feature_key" class="rounded border border-border px-3 py-2 text-sm" placeholder="feature_key" required>
                        <input type="text" name="label" class="rounded border border-border px-3 py-2 text-sm" placeholder="Label" required>
                        <input type="text" name="feature_group" class="rounded border border-border px-3 py-2 text-sm" placeholder="Group">
                        <input type="number" name="sort_order" value="999" class="rounded border border-border px-3 py-2 text-sm" placeholder="Sort" required>
                        <select name="value_type" class="rounded border border-border px-3 py-2 text-sm">
                            @foreach(['bool', 'int', 'string', 'json'] as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                        <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="is_highlight" value="1"> Highlight</label>
                        <label class="inline-flex items-center gap-2 text-sm text-textPrimary"><input type="checkbox" name="value_bool" value="1"> Bool true</label>
                        <input type="number" name="value_int" class="rounded border border-border px-3 py-2 text-sm" placeholder="Int value">
                        <input type="text" name="value_string" class="rounded border border-border px-3 py-2 text-sm md:col-span-2" placeholder="String value">
                        <input type="text" name="value_json" class="rounded border border-border px-3 py-2 text-sm md:col-span-2" placeholder='JSON value'>
                        <div class="md:col-span-6">
                            <button class="inline-flex items-center rounded border border-border px-3 py-1.5 text-xs">Add feature</button>
                        </div>
                    </form>
                </div>
            </section>
        @endforeach
    </div>
</div>
