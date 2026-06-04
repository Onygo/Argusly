<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'name',
    'url',
    'status',
    'events',
    'signing_secret',
    'failure_count',
    'last_delivered_at',
    'last_failed_at',
    'metadata',
])]
class WebhookEndpoint extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'paused', 'disabled'];

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint): void {
            $endpoint->uuid ??= (string) Str::uuid();
            $endpoint->status ??= 'active';
            $endpoint->failure_count ??= 0;
        });

        static::saving(function (WebhookEndpoint $endpoint): void {
            if (! in_array($endpoint->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid webhook endpoint status [{$endpoint->status}].");
            }

            $endpoint->validateBrand();
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

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'metadata' => 'array',
            'last_delivered_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    private function validateBrand(): void
    {
        if ($this->brand_id === null) {
            return;
        }

        $brand = Brand::query()->find($this->brand_id);

        if (! $brand || $this->account_id === null || $brand->account_id !== (int) $this->account_id) {
            throw new InvalidArgumentException('Webhook endpoint brand must belong to the same account.');
        }
    }
}
