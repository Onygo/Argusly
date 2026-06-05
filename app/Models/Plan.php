<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Plan extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'slug',
        'internal_code',
        'name',
        'description_short',
        'interval',
        'price_monthly_cents',
        'price_yearly_cents',
        'monthly_price_cents',
        'price_cents',
        'currency',
        'vat_included',
        'included_credits',
        'included_credits_per_interval',
        'credit_rollover_policy',
        'credit_expiry_days',
        'credit_rollover_monthly_cycles',
        'limits',
        'seat_limit',
        'has_required_onboarding',
        'onboarding_label',
        'onboarding_checkout_label',
        'onboarding_receipt_label',
        'onboarding_description',
        'onboarding_fee_cents',
        'onboarding_fee_currency',
        'onboarding_display_mode',
        'onboarding_is_visible_public',
        'onboarding_sort_order',
        'onboarding_internal_notes',
        'onboarding_effective_from',
        'is_active',
        'is_public',
        'billing_type',
        'billing_provider',
        'billing_provider_plan_key',
        'is_featured',
        'is_popular',
        'sort_order',
        'badge',
        'cta_label',
        'cta_href',
    ];

    protected $casts = [
        'slug' => 'string',
        'internal_code' => 'string',
        'description_short' => 'string',
        'interval' => 'string',
        'price_monthly_cents' => 'integer',
        'price_yearly_cents' => 'integer',
        'monthly_price_cents' => 'integer',
        'price_cents' => 'integer',
        'vat_included' => 'boolean',
        'included_credits' => 'integer',
        'included_credits_per_interval' => 'integer',
        'credit_rollover_policy' => 'string',
        'credit_expiry_days' => 'integer',
        'credit_rollover_monthly_cycles' => 'integer',
        'limits' => 'array',
        'seat_limit' => 'integer',
        'has_required_onboarding' => 'boolean',
        'onboarding_fee_cents' => 'integer',
        'onboarding_fee_currency' => 'string',
        'onboarding_display_mode' => 'string',
        'onboarding_is_visible_public' => 'boolean',
        'onboarding_sort_order' => 'integer',
        'onboarding_effective_from' => 'datetime',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'is_featured' => 'boolean',
        'is_popular' => 'boolean',
        'billing_type' => 'string',
        'billing_provider' => 'string',
        'billing_provider_plan_key' => 'string',
        'sort_order' => 'integer',
        'badge' => 'string',
        'cta_label' => 'string',
        'cta_href' => 'string',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function pendingSubscriptions()
    {
        return $this->hasMany(Subscription::class, 'pending_plan_id');
    }

    public function outgoingPlanChanges()
    {
        return $this->hasMany(SubscriptionPlanChange::class, 'from_plan_id');
    }

    public function incomingPlanChanges()
    {
        return $this->hasMany(SubscriptionPlanChange::class, 'to_plan_id');
    }

    public function features()
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function getMonthlyCreditAmountAttribute(): int
    {
        return $this->monthlyCredits();
    }

    public function monthlyCredits(): int
    {
        return max(0, (int) ($this->included_credits_per_interval ?: $this->included_credits));
    }

    public function scopePubliclyVisible($query)
    {
        return $query
            ->where('is_active', true)
            ->where(function ($builder): void {
                $builder->where('is_public', true)
                    ->orWhereNull('is_public');
            });
    }

    public function scopeFixedBilling($query)
    {
        return $query->where(function ($builder): void {
            $builder->where('billing_type', 'fixed')
                ->orWhere(function ($legacy): void {
                    $legacy->whereNull('billing_type')
                        ->where(function ($slugQuery): void {
                            $slugQuery->where('slug', '!=', 'enterprise')->orWhereNull('slug');
                        })
                        ->where(function ($keyQuery): void {
                            $keyQuery->where('key', '!=', 'enterprise')->orWhereNull('key');
                        });
                });
        });
    }

    public function scopeCustomBilling($query)
    {
        return $query->where(function ($builder): void {
            $builder->where('billing_type', 'custom')
                ->orWhere(function ($legacy): void {
                    $legacy->whereNull('billing_type')
                        ->where(function ($enterprise): void {
                            $enterprise->where('slug', 'enterprise')
                                ->orWhere('key', 'enterprise');
                        });
                });
        });
    }

    public function getIsFeaturedAttribute($value): bool
    {
        if ($value !== null) {
            return (bool) $value;
        }

        return (bool) ($this->attributes['is_popular'] ?? false);
    }

    public function getIsPublicAttribute($value): bool
    {
        if ($value !== null) {
            return (bool) $value;
        }

        return true;
    }

    public function getBillingTypeAttribute($value): string
    {
        $resolved = trim((string) $value);
        if ($resolved !== '') {
            return $resolved;
        }

        $slug = trim((string) ($this->attributes['slug'] ?? $this->attributes['key'] ?? ''));

        return $slug === 'enterprise' ? 'custom' : 'fixed';
    }

    public function getCtaUrlAttribute(): ?string
    {
        $value = trim((string) ($this->cta_href ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * Get the stable billing identifier for this plan.
     *
     * Uses internal_code if set, falls back to slug for backwards compatibility.
     * This identifier should be used for billing provider integration to ensure
     * stability even if the display slug changes.
     */
    public function getBillingIdentifierAttribute(): string
    {
        return trim((string) ($this->internal_code ?? $this->slug ?? $this->key ?? ''));
    }

    /**
     * @return array{
     *   required:bool,
     *   label:string,
     *   checkout_label:string,
     *   receipt_label:string,
     *   description:string,
     *   fee_cents:int,
     *   fee_currency:string,
     *   display_mode:string,
     *   is_visible_public:bool,
     *   sort_order:int,
     *   internal_notes:string,
     *   effective_from:\Illuminate\Support\Carbon|null
     * }
     */
    public function onboardingData(): array
    {
        $limits = is_array($this->limits) ? $this->limits : [];
        $legacyLabel = trim((string) ($limits['onboarding_label'] ?? ''));
        $label = trim((string) ($this->onboarding_label ?? ''));
        $resolvedLabel = $label !== '' ? $label : $legacyLabel;
        $resolvedMode = trim((string) ($this->onboarding_display_mode ?? ''));

        if ($resolvedMode === '') {
            $resolvedMode = $this->inferOnboardingDisplayMode($resolvedLabel);
        }

        $feeCents = $this->onboarding_fee_cents;
        if ($feeCents === null) {
            $feeCents = array_key_exists('onboarding_fee_cents', $limits)
                ? max(0, (int) $limits['onboarding_fee_cents'])
                : 0;
        }

        $required = $this->has_required_onboarding;
        if (! $required && array_key_exists('has_required_onboarding', $limits)) {
            $required = (bool) $limits['has_required_onboarding'];
        }

        return [
            'required' => (bool) $required,
            'label' => $resolvedLabel !== '' ? $resolvedLabel : $this->defaultOnboardingLabel($resolvedMode),
            'checkout_label' => trim((string) ($this->onboarding_checkout_label ?? '')) ?: ($resolvedLabel !== '' ? $resolvedLabel : $this->defaultOnboardingLabel($resolvedMode)),
            'receipt_label' => trim((string) ($this->onboarding_receipt_label ?? '')) ?: ($resolvedLabel !== '' ? $resolvedLabel : $this->defaultOnboardingLabel($resolvedMode)),
            'description' => trim((string) ($this->onboarding_description ?? '')) ?: trim((string) ($limits['onboarding_description'] ?? '')),
            'fee_cents' => max(0, (int) $feeCents),
            'fee_currency' => strtoupper(trim((string) ($this->onboarding_fee_currency ?: $this->currency ?: 'EUR'))),
            'display_mode' => $resolvedMode,
            'is_visible_public' => $this->onboarding_is_visible_public ?? true,
            'sort_order' => (int) ($this->onboarding_sort_order ?? 0),
            'internal_notes' => trim((string) ($this->onboarding_internal_notes ?? '')),
            'effective_from' => $this->onboarding_effective_from,
        ];
    }

    public function hasPaidRecurringPrice(): bool
    {
        return (int) ($this->price_cents ?: $this->monthly_price_cents ?: 0) > 0;
    }

    /**
     * Find a plan by its billing identifier (internal_code or slug).
     */
    public static function findByBillingIdentifier(string $identifier): ?self
    {
        return static::query()
            ->where('internal_code', $identifier)
            ->orWhere('slug', $identifier)
            ->orWhere('key', $identifier)
            ->first();
    }

    private function inferOnboardingDisplayMode(string $label): string
    {
        $normalized = strtolower(trim($label));

        return match (true) {
            str_contains($normalized, 'implementation') => 'implementation_onboarding',
            str_contains($normalized, 'launch') => 'launch_setup',
            default => 'guided_onboarding',
        };
    }

    private function defaultOnboardingLabel(string $displayMode): string
    {
        return match ($displayMode) {
            'implementation_onboarding' => 'Implementation Onboarding',
            'launch_setup' => 'Launch Setup',
            default => 'Guided Onboarding',
        };
    }
}
