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
    'property_id',
    'channel_id',
    'connector_manifest_id',
    'connector_version_id',
    'installed_by_user_id',
    'name',
    'status',
    'endpoint_url',
    'enabled_capabilities',
    'settings',
    'last_health_check',
    'last_health_checked_at',
    'installed_at',
    'revoked_at',
    'metadata',
])]
class ConnectorInstallation extends Model
{
    use HasFactory;

    public const STATUSES = [
        'pending',
        'active',
        'unhealthy',
        'disabled',
        'revoked',
        'archived',
    ];

    protected static function booted(): void
    {
        static::creating(function (ConnectorInstallation $installation): void {
            $installation->uuid ??= (string) Str::uuid();
            $installation->status ??= 'pending';
            $installation->installed_at ??= now();
        });

        static::saving(function (ConnectorInstallation $installation): void {
            self::assertTenantReferences($installation);
            self::assertVersionMatchesManifest($installation);
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
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<PublishingChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(PublishingChannel::class, 'channel_id');
    }

    /**
     * @return BelongsTo<ConnectorManifest, $this>
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(ConnectorManifest::class, 'connector_manifest_id');
    }

    /**
     * @return BelongsTo<ConnectorVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ConnectorVersion::class, 'connector_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by_user_id');
    }

    /**
     * @return HasMany<ConnectorLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ConnectorLog::class);
    }

    /**
     * @return HasMany<ConnectorToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(ConnectorToken::class);
    }

    protected function casts(): array
    {
        return [
            'enabled_capabilities' => 'array',
            'settings' => 'array',
            'last_health_check' => 'array',
            'last_health_checked_at' => 'datetime',
            'installed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    private static function assertTenantReferences(ConnectorInstallation $installation): void
    {
        if ($installation->brand_id !== null) {
            $brand = Brand::query()->find($installation->brand_id);

            if (! $brand || $brand->account_id !== $installation->account_id) {
                throw new InvalidArgumentException('Connector brand must belong to the same account.');
            }
        }

        if ($installation->property_id !== null) {
            $property = Property::query()->find($installation->property_id);

            if (! $property || $property->account_id !== $installation->account_id || $property->brand_id !== $installation->brand_id) {
                throw new InvalidArgumentException('Connector property must belong to the same account and brand.');
            }
        }

        if ($installation->channel_id !== null) {
            $channel = PublishingChannel::query()->find($installation->channel_id);

            if (! $channel || $channel->account_id !== $installation->account_id || $channel->brand_id !== $installation->brand_id) {
                throw new InvalidArgumentException('Connector channel must belong to the same account and brand.');
            }
        }
    }

    private static function assertVersionMatchesManifest(ConnectorInstallation $installation): void
    {
        $version = ConnectorVersion::query()->find($installation->connector_version_id);

        if (! $version || $version->connector_manifest_id !== $installation->connector_manifest_id) {
            throw new InvalidArgumentException('Connector version must belong to the selected manifest.');
        }
    }
}
