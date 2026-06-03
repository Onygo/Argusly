<?php

namespace App\Models;

use App\Models\Concerns\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'name',
    'slug',
    'description',
    'objective',
    'status',
    'start_date',
    'end_date',
    'metadata',
])]
class Campaign extends Model
{
    use HasFactory, RecordsDomainEvents;

    public const STATUSES = ['draft', 'planned', 'active', 'paused', 'completed', 'archived'];

    public const TYPES = ['social', 'influencer', 'content', 'pr'];

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign): void {
            $campaign->uuid ??= (string) Str::uuid();
            $campaign->slug = $campaign->slug ?: Str::slug($campaign->name);
            $campaign->status ??= 'draft';
        });

        static::created(function (Campaign $campaign): void {
            app(\App\Services\DomainEventService::class)->recordForSubject('CampaignCreated', $campaign, null, [
                'name' => $campaign->name,
            ], dispatch: false);
            app(\App\Services\Graph\GraphProjectionService::class)->project($campaign);
        });

        static::saving(function (Campaign $campaign): void {
            if (! in_array($campaign->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid campaign status [{$campaign->status}].");
            }

            $brand = Brand::query()->find($campaign->brand_id);

            if (! $brand || $brand->account_id !== $campaign->account_id) {
                throw new InvalidArgumentException('Campaign brand must belong to the same account.');
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
     * @return BelongsToMany<ContentAsset, $this>
     */
    public function contentAssets(): BelongsToMany
    {
        return $this->belongsToMany(ContentAsset::class, 'campaign_assets')
            ->withTimestamps()
            ->latest('content_assets.updated_at');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(CampaignStage::class)->orderBy('position');
    }

    public function boardItems(): HasMany
    {
        return $this->hasMany(CampaignItem::class)->orderBy('position')->latest();
    }

    /**
     * @return BelongsToMany<Topic, $this>
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'campaign_topics')
            ->withTimestamps()
            ->orderBy('topics.name');
    }

    /**
     * @return BelongsToMany<IntelligenceSignal, $this>
     */
    public function signals(): BelongsToMany
    {
        return $this->belongsToMany(IntelligenceSignal::class, 'campaign_signals')
            ->withTimestamps()
            ->latest('intelligence_signals.detected_at');
    }

    /**
     * @param  Builder<Campaign>  $query
     * @return Builder<Campaign>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['draft', 'planned', 'active', 'paused']);
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'metadata' => 'array',
        ];
    }
}
