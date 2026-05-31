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
    'connector_installation_id',
    'provider',
    'name',
    'status',
    'credentials',
    'settings',
    'last_connected_at',
])]
class PublishingChannel extends Model
{
    use HasFactory;

    public const PROVIDERS = [
        'wordpress',
        'laravel',
        'linkedin',
        'x',
        'meta',
        'youtube',
        'email',
        'webhook',
        'api',
    ];

    public const STATUSES = [
        'draft',
        'active',
        'disconnected',
        'failed',
        'archived',
    ];

    protected static function booted(): void
    {
        static::creating(function (PublishingChannel $channel): void {
            $channel->uuid ??= (string) Str::uuid();
            $channel->status ??= 'draft';
        });

        static::saving(function (PublishingChannel $channel): void {
            if ($channel->property_id === null) {
                self::assertConnectorInstallation($channel);

                return;
            }

            $property = Property::query()->find($channel->property_id);

            if (! $property || $property->account_id !== $channel->account_id || $property->brand_id !== $channel->brand_id) {
                throw new InvalidArgumentException('Publishing channel property must belong to the same account and brand.');
            }

            self::assertConnectorInstallation($channel);
        });

        static::saved(function (PublishingChannel $channel): void {
            if (! $channel->connector_installation_id) {
                return;
            }

            ConnectorInstallation::withoutEvents(function () use ($channel): void {
                ConnectorInstallation::query()
                    ->whereKey($channel->connector_installation_id)
                    ->where('channel_id', '!=', $channel->id)
                    ->update(['channel_id' => $channel->id]);
            });
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
     * @return BelongsTo<ConnectorInstallation, $this>
     */
    public function connectorInstallation(): BelongsTo
    {
        return $this->belongsTo(ConnectorInstallation::class, 'connector_installation_id');
    }

    /**
     * @return HasMany<ContentAsset, $this>
     */
    public function contentAssets(): HasMany
    {
        return $this->hasMany(ContentAsset::class, 'channel_id');
    }

    /**
     * @return HasMany<PublishingAction, $this>
     */
    public function publishingActions(): HasMany
    {
        return $this->hasMany(PublishingAction::class);
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_connected_at' => 'datetime',
        ];
    }

    private static function assertConnectorInstallation(PublishingChannel $channel): void
    {
        if ($channel->connector_installation_id === null) {
            return;
        }

        $installation = ConnectorInstallation::query()
            ->with('manifest')
            ->find($channel->connector_installation_id);

        if (
            ! $installation
            || $installation->account_id !== $channel->account_id
            || $installation->brand_id !== $channel->brand_id
            || ($installation->property_id !== null && $installation->property_id !== $channel->property_id)
        ) {
            throw new InvalidArgumentException('Connector installation must belong to the same account, brand and property scope as the publishing channel.');
        }

        if ($installation->manifest?->type !== $channel->provider) {
            throw new InvalidArgumentException('Connector installation type must match the publishing channel provider.');
        }
    }
}
