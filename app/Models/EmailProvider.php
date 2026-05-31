<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'provider',
    'name',
    'status',
    'settings',
    'credentials',
    'last_verified_at',
])]
class EmailProvider extends Model
{
    use HasFactory;

    public const PROVIDERS = ['mailgun', 'sendgrid', 'brevo', 'smtp', 'webhook'];

    public const STATUSES = ['active', 'inactive', 'failed', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (EmailProvider $provider): void {
            $provider->uuid ??= (string) Str::uuid();
            $provider->status ??= 'active';
        });

        static::saving(function (EmailProvider $provider): void {
            if (! in_array($provider->provider, self::PROVIDERS, true)) {
                throw new InvalidArgumentException("Invalid email provider [{$provider->provider}].");
            }

            if (! in_array($provider->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid email provider status [{$provider->status}].");
            }

            $provider->validateBrand();
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

    public function newsletters(): HasMany
    {
        return $this->hasMany(Newsletter::class);
    }

    public function scopeForTenant(Builder $query, Account $account, ?Brand $brand = null): Builder
    {
        return $query->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $scope) => $scope->where(fn (Builder $brandScope) => $brandScope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $scope) => $scope->whereNull('brand_id'),
            );
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'credentials' => 'encrypted:array',
            'last_verified_at' => 'datetime',
        ];
    }

    private function validateBrand(): void
    {
        if ($this->brand_id === null) {
            return;
        }

        $brand = Brand::query()->find($this->brand_id);

        if (! $brand || $brand->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Email provider brand must belong to the same account.');
        }
    }
}
