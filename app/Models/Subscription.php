<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'plan_id',
        'pending_plan_id',
        'interval',
        'price_cents',
        'currency',
        'included_credits_per_interval',
        'seat_limit',
        'status',
        'status_reason',
        'current_period_start',
        'current_period_end',
        'next_payment_at',
        'billing_cycle_anchor',
        'grace_period_ends_at',
        'provider',
        'provider_customer_id',
        'provider_mandate_id',
        'mandate_last_checked_at',
        'provider_subscription_id',
        'provider_payment_id',
        'canceled_at',
        'suspended_at',
        'meta',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'included_credits_per_interval' => 'integer',
        'seat_limit' => 'integer',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'next_payment_at' => 'datetime',
        'billing_cycle_anchor' => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'mandate_last_checked_at' => 'datetime',
        'canceled_at' => 'datetime',
        'suspended_at' => 'datetime',
        'meta' => 'array',
    ];

    public function clientSite()
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function pendingPlan()
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function planChanges()
    {
        return $this->hasMany(SubscriptionPlanChange::class);
    }

    public function getIsActiveAttribute(): bool
    {
        return in_array((string) $this->status, ['active', 'trialing'], true);
    }

    public function getIsPastDueAttribute(): bool
    {
        return (string) $this->status === 'past_due';
    }

    public function getDaysUntilRenewalAttribute(): ?int
    {
        if (! $this->next_payment_at) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->next_payment_at->copy()->startOfDay(), false);
    }

    public function getCreditsNextRenewalAttribute(): int
    {
        return $this->monthlyCredits();
    }

    public function monthlyCredits(): int
    {
        $planCredits = null;

        if ($this->relationLoaded('plan')) {
            $planCredits = $this->plan?->monthlyCredits();
        } elseif ($this->plan_id) {
            $planCredits = $this->plan()
                ->select(['id', 'included_credits', 'included_credits_per_interval'])
                ->first()?->monthlyCredits();
        }

        if ($planCredits !== null) {
            return max(0, (int) $planCredits);
        }

        return max(0, (int) ($this->included_credits_per_interval ?? 0));
    }
}
