<?php

namespace App\Models;

use App\Enums\Billing\SubscriptionPlanChangeStatus;
use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class SubscriptionPlanChange extends Model
{
    use HasUuids;

    protected $fillable = [
        'subscription_id',
        'organization_id',
        'from_plan_id',
        'to_plan_id',
        'strategy',
        'status',
        'proration_amount_cents',
        'currency',
        'payment_intent_id',
        'invoice_id',
        'effective_at',
        'applied_at',
        'blocked_reason',
        'meta',
    ];

    protected $casts = [
        'status' => SubscriptionPlanChangeStatus::class,
        'proration_amount_cents' => 'integer',
        'effective_at' => 'datetime',
        'applied_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $change): void {
            $resolved = $change->status instanceof SubscriptionPlanChangeStatus
                ? $change->status
                : SubscriptionPlanChangeStatus::tryFrom((string) $change->status);

            if (! $resolved) {
                throw new InvalidArgumentException('Invalid subscription plan change status.');
            }

            if ($change->exists && $change->isDirty('status')) {
                $originalRaw = $change->getRawOriginal('status');
                $original = is_string($originalRaw)
                    ? SubscriptionPlanChangeStatus::tryFrom($originalRaw)
                    : null;

                if ($original && ! $original->canTransitionTo($resolved)) {
                    throw new DomainException(sprintf(
                        'Invalid plan change status transition from %s to %s.',
                        $original->value,
                        $resolved->value,
                    ));
                }
            }

            $change->attributes['status'] = $resolved->value;
        });
    }

    public function isPending(): bool
    {
        return $this->status instanceof SubscriptionPlanChangeStatus
            ? $this->status->isPending()
            : false;
    }

    public function isFinal(): bool
    {
        return $this->status instanceof SubscriptionPlanChangeStatus
            ? $this->status->isFinal()
            : false;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function transitionTo(SubscriptionPlanChangeStatus $nextStatus, array $attributes = []): self
    {
        if ($this->status instanceof SubscriptionPlanChangeStatus
            && ! $this->status->canTransitionTo($nextStatus)) {
            throw new DomainException(sprintf(
                'Invalid plan change status transition from %s to %s.',
                $this->status->value,
                $nextStatus->value,
            ));
        }

        $this->status = $nextStatus;
        $this->fill($attributes);
        $this->save();

        return $this;
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function fromPlan()
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function toPlan()
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }

    public function paymentIntent()
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
