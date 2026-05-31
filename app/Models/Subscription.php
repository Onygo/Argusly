<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id',
    'plan_id',
    'status',
    'billing_interval',
    'currency',
    'amount',
    'provider',
    'provider_customer_id',
    'provider_subscription_id',
    'trial_ends_at',
    'current_period_starts_at',
    'current_period_ends_at',
    'canceled_at',
    'metadata',
])]
class Subscription extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<SubscriptionModule, $this>
     */
    public function modules(): HasMany
    {
        return $this->hasMany(SubscriptionModule::class);
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['trialing', 'active', 'past_due']);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['trialing', 'active', 'past_due'], true)
            && ($this->canceled_at === null || $this->canceled_at->isFuture());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
