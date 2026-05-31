<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'name',
    'description',
])]
class TopicCluster extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (TopicCluster $cluster): void {
            $cluster->uuid ??= (string) Str::uuid();
        });

        static::saving(function (TopicCluster $cluster): void {
            $brand = Brand::query()->find($cluster->brand_id);

            if (! $brand || $brand->account_id !== $cluster->account_id) {
                throw new InvalidArgumentException('Topic cluster brand must belong to the same account.');
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
     * @return BelongsToMany<Topic, $this>
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'topic_cluster_topics')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position')
            ->orderBy('topics.name');
    }
}
