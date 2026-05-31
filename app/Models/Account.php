<?php

namespace App\Models;

use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'status', 'default_locale', 'default_content_language', 'settings'])]
class Account extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (Account $account): void {
            app(ActivityLogger::class)->log(
                event: 'account.created',
                description: "Account {$account->name} was created.",
                account: $account,
                subject: $account,
            );
        });
    }

    /**
     * @return HasMany<Brand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot(['status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasOne<Subscription, $this>
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->active()->latestOfMany();
    }

    /**
     * @return HasMany<SubscriptionModule, $this>
     */
    public function subscriptionModules(): HasMany
    {
        return $this->hasMany(SubscriptionModule::class);
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

    /**
     * @return HasOne<CreditBalance, $this>
     */
    public function creditBalance(): HasOne
    {
        return $this->hasOne(CreditBalance::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
