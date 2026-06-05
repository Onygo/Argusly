<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'user_id',
    'account_id',
    'brand_id',
    'type',
    'channel',
    'enabled',
])]
class NotificationPreference extends Model
{
    use HasFactory;

    public const CHANNELS = ['in_app', 'email', 'slack', 'webhook'];

    public const TYPES = [
        'integration_expired',
        'publishing_failed',
        'traffic_drop',
        'visibility_drop',
        'recommendation_created',
        'approval_requested',
        'agent_task_completed',
        'credits_low',
        'operational_alert',
        'queue_failure',
        'worker_unhealthy',
        'source_unhealthy',
        'scheduler_failure',
        'webhook_failed',
    ];

    protected static function booted(): void
    {
        static::creating(function (NotificationPreference $preference): void {
            $preference->uuid ??= (string) Str::uuid();
            $preference->channel ??= 'in_app';
        });

        static::saving(function (NotificationPreference $preference): void {
            self::assertValid($preference);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    private static function assertValid(NotificationPreference $preference): void
    {
        if (! in_array($preference->type, self::TYPES, true)) {
            throw new InvalidArgumentException("Invalid notification type [{$preference->type}].");
        }

        if (! in_array($preference->channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Invalid notification channel [{$preference->channel}].");
        }

        if ($preference->brand_id !== null) {
            $brand = Brand::query()->find($preference->brand_id);

            if (! $brand || $brand->account_id !== $preference->account_id) {
                throw new InvalidArgumentException('Notification preference brand must belong to the same account.');
            }
        }
    }
}
