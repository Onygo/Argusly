<?php

namespace App\Models;

use App\Models\Concerns\HasTopics;
use App\Models\Concerns\RecordsDomainEvents;
use App\Services\ContentLanguageService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'property_id',
    'channel_id',
    'type',
    'status',
    'title',
    'slug',
    'language',
    'locale',
    'source',
    'source_url',
    'canonical_url',
    'excerpt',
    'body',
    'metadata',
    'seo_metadata',
    'published_at',
    'first_published_at',
    'last_refreshed_at',
    'created_by',
    'updated_by',
])]
class ContentAsset extends Model
{
    use HasFactory, HasTopics, RecordsDomainEvents, SoftDeletes;

    public const TYPES = [
        'article',
        'page',
        'social_post',
        'newsletter',
        'landing_page',
        'faq',
        'answer_block',
        'campaign_asset',
    ];

    public const STATUSES = [
        'draft',
        'review',
        'approved',
        'scheduled',
        'published',
        'archived',
        'failed',
    ];

    protected static function booted(): void
    {
        static::creating(function (ContentAsset $asset): void {
            $asset->uuid ??= (string) Str::uuid();
            $asset->slug = $asset->slug ?: Str::slug($asset->title);
            $asset->language ??= app(ContentLanguageService::class)->defaultFor($asset->brand, $asset->account);
            $asset->locale ??= 'en_US';
            $asset->source ??= 'manual';
            $asset->status ??= 'draft';
        });

        static::saving(function (ContentAsset $asset): void {
            $asset->language ??= app(ContentLanguageService::class)->defaultFor($asset->brand, $asset->account);
            $asset->locale ??= 'en_US';

            if ($asset->property_id !== null) {
                $property = Property::query()->find($asset->property_id);

                if (! $property || $property->account_id !== $asset->account_id || $property->brand_id !== $asset->brand_id) {
                    throw new InvalidArgumentException('Content asset property must belong to the same account and brand.');
                }
            }

            if ($asset->channel_id !== null) {
                $channel = PublishingChannel::query()->find($asset->channel_id);

                if (! $channel || $channel->account_id !== $asset->account_id || $channel->brand_id !== $asset->brand_id) {
                    throw new InvalidArgumentException('Content asset publishing channel must belong to the same account and brand.');
                }
            }

            app(ContentLanguageService::class)->validateForBrand($asset->language, $asset->brand);
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
    public function publishingChannel(): BelongsTo
    {
        return $this->belongsTo(PublishingChannel::class, 'channel_id');
    }

    /**
     * @return HasMany<GeneratedAsset, $this>
     */
    public function generatedAssets(): HasMany
    {
        return $this->hasMany(GeneratedAsset::class);
    }

    public function sourceTranslations(): HasMany
    {
        return $this->hasMany(ContentTranslation::class, 'source_content_asset_id');
    }

    public function translatedFrom(): HasMany
    {
        return $this->hasMany(ContentTranslation::class, 'translated_content_asset_id');
    }

    /**
     * @return HasMany<ContentAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(ContentAudit::class);
    }

    /**
     * @return HasMany<ContentAudit, $this>
     */
    public function latestAudit(): HasMany
    {
        return $this->hasMany(ContentAudit::class)->latest('audited_at')->latest();
    }

    /**
     * @return HasMany<AnswerBlock, $this>
     */
    public function answerBlocks(): HasMany
    {
        return $this->hasMany(AnswerBlock::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<ContentLifecycleScore, $this>
     */
    public function lifecycleScores(): HasMany
    {
        return $this->hasMany(ContentLifecycleScore::class);
    }

    /**
     * @return HasMany<ContentLifecycleScore, $this>
     */
    public function latestLifecycleScore(): HasMany
    {
        return $this->hasMany(ContentLifecycleScore::class)->latest('scored_at')->latest();
    }

    /**
     * @return HasMany<PublishingAction, $this>
     */
    public function publishingActions(): HasMany
    {
        return $this->hasMany(PublishingAction::class);
    }

    /**
     * @return HasMany<SocialPost, $this>
     */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /**
     * @return HasMany<SocialPostVariant, $this>
     */
    public function socialPostVariants(): HasMany
    {
        return $this->hasMany(SocialPostVariant::class);
    }

    /**
     * @return HasMany<Ga4MetricSnapshot, $this>
     */
    public function ga4MetricSnapshots(): HasMany
    {
        return $this->hasMany(Ga4MetricSnapshot::class);
    }

    /**
     * @return HasMany<Ga4MetricSnapshot, $this>
     */
    public function latestGa4MetricSnapshots(): HasMany
    {
        return $this->hasMany(Ga4MetricSnapshot::class)->latest('date')->latest();
    }

    /**
     * @return HasMany<SearchConsoleQuerySnapshot, $this>
     */
    public function searchConsoleQuerySnapshots(): HasMany
    {
        return $this->hasMany(SearchConsoleQuerySnapshot::class);
    }

    /**
     * @return HasMany<SearchConsoleQuerySnapshot, $this>
     */
    public function latestSearchConsoleQuerySnapshots(): HasMany
    {
        return $this->hasMany(SearchConsoleQuerySnapshot::class)->latest('date')->latest();
    }

    /**
     * @return BelongsToMany<Campaign, $this>
     */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_assets')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param  Builder<ContentAsset>  $query
     * @return Builder<ContentAsset>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNotNull('published_at');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'seo_metadata' => 'array',
            'published_at' => 'datetime',
            'first_published_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }
}
