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
    'webhook_endpoint_id',
    'account_id',
    'brand_id',
    'event',
    'status',
    'idempotency_key',
    'payload',
    'attempts',
    'response_status',
    'response_body',
    'error_message',
    'available_at',
    'next_retry_at',
    'delivered_at',
    'failed_at',
])]
class WebhookDelivery extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'processing', 'delivered', 'failed', 'cancelled'];

    protected static function booted(): void
    {
        static::creating(function (WebhookDelivery $delivery): void {
            $delivery->uuid ??= (string) Str::uuid();
            $delivery->status ??= 'pending';
            $delivery->attempts ??= 0;
            $delivery->idempotency_key ??= (string) Str::uuid();
        });

        static::saving(function (WebhookDelivery $delivery): void {
            if (! in_array($delivery->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid webhook delivery status [{$delivery->status}].");
            }

            $delivery->validateBrand();
        });
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

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
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    private function validateBrand(): void
    {
        if ($this->brand_id === null) {
            return;
        }

        $brand = Brand::query()->find($this->brand_id);

        if (! $brand || $this->account_id === null || $brand->account_id !== (int) $this->account_id) {
            throw new InvalidArgumentException('Webhook delivery brand must belong to the same account.');
        }
    }
}
