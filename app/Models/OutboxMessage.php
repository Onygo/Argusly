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
    'type',
    'status',
    'payload',
    'available_at',
    'attempts',
    'last_attempted_at',
    'processed_at',
    'error',
])]
class OutboxMessage extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'processing', 'completed', 'failed', 'cancelled'];

    public const TYPES = [
        'wordpress_publishing',
        'laravel_connector',
        'linkedin_publishing',
        'oauth_callback',
        'ai_visibility_provider_call',
        'email_newsletter_dispatch',
        'external_webhook',
    ];

    protected static function booted(): void
    {
        static::creating(function (OutboxMessage $message): void {
            $message->uuid ??= (string) Str::uuid();
            $message->status ??= 'pending';
            $message->attempts ??= 0;
        });

        static::saving(function (OutboxMessage $message): void {
            if (! in_array($message->type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid outbox message type [{$message->type}].");
            }

            if (! in_array($message->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid outbox message status [{$message->status}].");
            }

            if ($message->brand_id !== null) {
                $brand = Brand::query()->find($message->brand_id);

                if (! $brand || $brand->account_id !== $message->account_id) {
                    throw new InvalidArgumentException('Outbox message brand must belong to the same account.');
                }
            }
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where(fn (Builder $scope) => $scope
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()));
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
