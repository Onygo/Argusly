<?php

namespace App\Models;

use App\Models\Concerns\HasEvidence;
use App\Models\Concerns\HasTopics;
use App\Models\Concerns\RecordsDomainEvents;
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
    'source_id',
    'title',
    'content',
    'url',
    'author',
    'published_at',
    'sentiment',
    'impact_score',
    'metadata',
])]
class Mention extends Model
{
    use HasEvidence, HasFactory, HasTopics, RecordsDomainEvents;

    public const SENTIMENTS = ['positive', 'neutral', 'negative', 'mixed'];

    protected static function booted(): void
    {
        static::creating(function (Mention $mention): void {
            $mention->uuid ??= (string) Str::uuid();
        });

        static::saving(function (Mention $mention): void {
            if ($mention->sentiment !== null && ! in_array($mention->sentiment, self::SENTIMENTS, true)) {
                throw new InvalidArgumentException("Invalid mention sentiment [{$mention->sentiment}].");
            }

            if ($mention->brand_id !== null) {
                $brand = Brand::query()->find($mention->brand_id);

                if (! $brand || $brand->account_id !== $mention->account_id) {
                    throw new InvalidArgumentException('Mention brand must belong to the same account.');
                }
            }

            if ($mention->source_id !== null) {
                $source = Source::query()->find($mention->source_id);

                if (! $source || $source->account_id !== null && $source->account_id !== $mention->account_id) {
                    throw new InvalidArgumentException('Mention source must be a configured source in the same account.');
                }

                if ($source->brand_id !== null && $source->brand_id !== $mention->brand_id) {
                    throw new InvalidArgumentException('Mention source must belong to the same brand scope.');
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
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    /**
     * @return HasMany<MentionEntity, $this>
     */
    public function entities(): HasMany
    {
        return $this->hasMany(MentionEntity::class);
    }

    /**
     * @return HasMany<MentionRelationship, $this>
     */
    public function relationships(): HasMany
    {
        return $this->hasMany(MentionRelationship::class);
    }

    /**
     * @param  Builder<Mention>  $query
     * @return Builder<Mention>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByRaw('published_at is null')
            ->latest('published_at')
            ->latest();
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'impact_score' => 'integer',
            'metadata' => 'array',
        ];
    }
}
