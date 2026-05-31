<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'provider_run_id',
    'entity_name',
    'entity_type',
    'sentiment',
    'position',
    'metadata',
])]
class VisibilityAnswerEntity extends Model
{
    use HasFactory;

    public const SENTIMENTS = ['positive', 'neutral', 'negative', 'mixed'];

    protected static function booted(): void
    {
        static::creating(function (VisibilityAnswerEntity $entity): void {
            $entity->uuid ??= (string) Str::uuid();
        });

        static::saving(function (VisibilityAnswerEntity $entity): void {
            if ($entity->sentiment !== null && ! in_array($entity->sentiment, self::SENTIMENTS, true)) {
                throw new InvalidArgumentException("Invalid visibility answer entity sentiment [{$entity->sentiment}].");
            }

            $run = VisibilityProviderRun::query()->find($entity->provider_run_id);

            if (! $run || $run->account_id !== $entity->account_id || $run->brand_id !== $entity->brand_id) {
                throw new InvalidArgumentException('Visibility answer entity provider run must belong to the same tenant.');
            }
        });
    }

    /**
     * @return BelongsTo<VisibilityProviderRun, $this>
     */
    public function providerRun(): BelongsTo
    {
        return $this->belongsTo(VisibilityProviderRun::class, 'provider_run_id');
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'metadata' => 'array',
        ];
    }
}
