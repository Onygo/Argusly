<?php

namespace App\Models;

use App\Models\Concerns\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'integration_id',
    'owner_user_id',
    'account_id',
    'brand_id',
    'name',
    'status',
    'provider_account_id',
    'provider_account_name',
    'scopes',
    'access_token',
    'refresh_token',
    'token_payload',
    'token_expires_at',
    'refresh_expires_at',
    'last_used_at',
    'revoked_at',
    'metadata',
])]
class IntegrationConnection extends Model
{
    use HasFactory, RecordsDomainEvents;

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
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
     * @return HasMany<IntegrationPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(IntegrationPermission::class);
    }

    /**
     * @return HasMany<SourceConnection, $this>
     */
    public function sourceConnections(): HasMany
    {
        return $this->hasMany(SourceConnection::class);
    }

    /**
     * @return HasMany<Ga4Property, $this>
     */
    public function ga4Properties(): HasMany
    {
        return $this->hasMany(Ga4Property::class);
    }

    /**
     * @return HasMany<SearchConsoleSite, $this>
     */
    public function searchConsoleSites(): HasMany
    {
        return $this->hasMany(SearchConsoleSite::class);
    }

    /**
     * @param  Builder<IntegrationConnection>  $query
     * @return Builder<IntegrationConnection>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNull('revoked_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_payload' => 'encrypted:array',
            'token_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
