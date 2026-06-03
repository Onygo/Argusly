<?php

namespace App\Models;

use App\Models\Concerns\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
    'title',
    'description',
    'narrative_type',
    'status',
    'importance',
])]
class Narrative extends Model
{
    use HasFactory, RecordsDomainEvents;

    public const TYPES = ['brand', 'product', 'service', 'campaign', 'competitor'];

    public const STATUSES = ['draft', 'active', 'archived'];

    public const IMPORTANCE_LEVELS = ['low', 'medium', 'high', 'critical'];

    protected static function booted(): void
    {
        static::creating(function (Narrative $narrative): void {
            $narrative->uuid ??= (string) Str::uuid();
            $narrative->status ??= 'draft';
            $narrative->importance ??= 'medium';
        });

        static::saving(function (Narrative $narrative): void {
            if (! in_array($narrative->narrative_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid narrative type [{$narrative->narrative_type}].");
            }

            if (! in_array($narrative->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid narrative status [{$narrative->status}].");
            }

            if (! in_array($narrative->importance, self::IMPORTANCE_LEVELS, true)) {
                throw new InvalidArgumentException("Invalid narrative importance [{$narrative->importance}].");
            }

            $brand = Brand::query()->find($narrative->brand_id);

            if (! $brand || $brand->account_id !== $narrative->account_id) {
                throw new InvalidArgumentException('Narrative brand must belong to the same account.');
            }
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

    public function observations(): HasMany
    {
        return $this->hasMany(NarrativeObservation::class);
    }

    public function gaps(): HasMany
    {
        return $this->hasMany(NarrativeGap::class);
    }

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'narrative_topics')->withTimestamps();
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'narrative_entities')->withTimestamps();
    }

    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(Mention::class, 'narrative_mentions')->withTimestamps();
    }

    public function competitors(): BelongsToMany
    {
        return $this->belongsToMany(Competitor::class, 'narrative_competitors')->withTimestamps();
    }

    public function visibilityProviderRuns(): BelongsToMany
    {
        return $this->belongsToMany(VisibilityProviderRun::class, 'narrative_visibility_provider_runs')->withTimestamps();
    }
}
