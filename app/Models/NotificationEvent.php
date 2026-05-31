<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'user_id',
    'domain_event_id',
    'type',
    'channel',
    'title',
    'body',
    'payload',
    'delivered_at',
    'read_at',
])]
class NotificationEvent extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (NotificationEvent $event): void {
            $event->uuid ??= (string) Str::uuid();
            $event->channel ??= 'in_app';
            $event->delivered_at ??= now();
        });

        static::saving(function (NotificationEvent $event): void {
            self::assertValid($event);
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function domainEvent(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function markRead(): bool
    {
        return $this->forceFill(['read_at' => now()])->save();
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    private static function assertValid(NotificationEvent $event): void
    {
        if (! in_array($event->type, NotificationPreference::TYPES, true)) {
            throw new InvalidArgumentException("Invalid notification type [{$event->type}].");
        }

        if (! in_array($event->channel, NotificationPreference::CHANNELS, true)) {
            throw new InvalidArgumentException("Invalid notification channel [{$event->channel}].");
        }

        if ($event->brand_id !== null) {
            $brand = Brand::query()->find($event->brand_id);

            if (! $brand || $brand->account_id !== $event->account_id) {
                throw new InvalidArgumentException('Notification event brand must belong to the same account.');
            }
        }

        if ($event->domain_event_id !== null) {
            $domainEvent = DomainEvent::query()->find($event->domain_event_id);

            if (! $domainEvent || $domainEvent->account_id !== $event->account_id || $domainEvent->brand_id !== $event->brand_id) {
                throw new InvalidArgumentException('Notification event domain event must belong to the same tenant.');
            }
        }
    }
}
