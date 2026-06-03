<?php

namespace App\Models;

use App\Services\ActivityLogger;
use App\Services\DomainEventService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'account_id',
    'name',
    'slug',
    'domain',
    'description',
    'website_url',
    'market',
    'language',
    'default_content_language',
    'enabled_content_languages',
    'status',
])]
class Brand extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (Brand $brand): void {
            app(ActivityLogger::class)->log(
                event: 'brand.created',
                description: "Brand {$brand->name} was created.",
                account: $brand->account,
                brand: $brand,
                subject: $brand,
            );

            app(DomainEventService::class)->recordForSubject('BrandCreated', $brand, null, [
                'name' => $brand->name,
            ], dispatch: false);
            app(\App\Services\Graph\GraphProjectionService::class)->project($brand);
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
     * @return HasMany<BrandMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(BrandMembership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'brand_memberships')
            ->withPivot(['account_id', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasOne<BrandProfile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(BrandProfile::class);
    }

    /**
     * @return HasMany<BrandProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(BrandProduct::class);
    }

    /**
     * @return HasMany<BrandService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(BrandService::class);
    }

    /**
     * @return HasMany<BrandNarrative, $this>
     */
    public function narratives(): HasMany
    {
        return $this->hasMany(BrandNarrative::class);
    }

    /**
     * @return BelongsToMany<Topic, $this>
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'brand_topics')
            ->withPivot(['priority', 'importance_score'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<IntegrationConnection, $this>
     */
    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    /**
     * @return HasMany<IntegrationPermission, $this>
     */
    public function integrationPermissions(): HasMany
    {
        return $this->hasMany(IntegrationPermission::class);
    }

    /**
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * @return HasMany<PublishingChannel, $this>
     */
    public function publishingChannels(): HasMany
    {
        return $this->hasMany(PublishingChannel::class);
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

    protected function casts(): array
    {
        return [
            'enabled_content_languages' => 'array',
        ];
    }
}
