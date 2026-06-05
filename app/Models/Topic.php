<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;
use App\Services\DomainEventService;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'name',
    'slug',
    'description',
    'status',
    'metadata',
])]
class Topic extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'watching', 'paused', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (Topic $topic): void {
            $topic->uuid ??= (string) Str::uuid();
            $topic->slug = $topic->slug ?: Str::slug($topic->name);
            $topic->status ??= 'active';
        });

        static::created(function (Topic $topic): void {
            if ($topic->account_id !== null) {
                app(DomainEventService::class)->recordForSubject('TopicCreated', $topic, null, [
                    'name' => $topic->name,
                ], dispatch: false);
            }

            if ($topic->account_id !== null) {
                app(\App\Services\Graph\GraphProjectionService::class)->project($topic);
            }
        });

        static::saving(function (Topic $topic): void {
            $topic->status ??= 'active';

            if (! in_array($topic->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid topic status [{$topic->status}].");
            }

            if ($topic->brand_id !== null) {
                $brand = Brand::query()->find($topic->brand_id);

                if (! $brand || $topic->account_id === null || $brand->account_id !== $topic->account_id) {
                    throw new InvalidArgumentException('Topic brand must belong to the same account.');
                }
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
     * @return BelongsToMany<Brand, $this>
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_topics')
            ->withPivot(['priority', 'importance_score'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<TopicCluster, $this>
     */
    public function clusters(): BelongsToMany
    {
        return $this->belongsToMany(TopicCluster::class, 'topic_cluster_topics')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * @return HasMany<TopicRelationship, $this>
     */
    public function childRelationships(): HasMany
    {
        return $this->hasMany(TopicRelationship::class, 'parent_topic_id');
    }

    /**
     * @return HasMany<TopicRelationship, $this>
     */
    public function parentRelationships(): HasMany
    {
        return $this->hasMany(TopicRelationship::class, 'child_topic_id');
    }

    /**
     * @return MorphToMany<ContentAsset, $this>
     */
    public function contentAssets(): MorphToMany
    {
        return $this->morphedByMany(ContentAsset::class, 'topicable')
            ->withPivot(['account_id', 'brand_id', 'relationship_type', 'relevance_score'])
            ->withTimestamps();
    }

    /**
     * @return MorphToMany<Mention, $this>
     */
    public function mentions(): MorphToMany
    {
        return $this->morphedByMany(Mention::class, 'topicable')
            ->withPivot(['account_id', 'brand_id', 'relationship_type', 'relevance_score'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Campaign, $this>
     */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_topics')
            ->withTimestamps();
    }

    /**
     * @param  Builder<Topic>  $query
     * @return Builder<Topic>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
