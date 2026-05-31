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
    'url',
    'domain',
    'title',
    'snippet',
    'rank',
    'trust_score',
    'metadata',
])]
class VisibilityCitation extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (VisibilityCitation $citation): void {
            $citation->uuid ??= (string) Str::uuid();
        });

        static::saving(function (VisibilityCitation $citation): void {
            if ($citation->trust_score !== null && ($citation->trust_score < 0 || $citation->trust_score > 100)) {
                throw new InvalidArgumentException('Visibility citation trust score must be between 0 and 100.');
            }

            $run = VisibilityProviderRun::query()->find($citation->provider_run_id);

            if (! $run || $run->account_id !== $citation->account_id || $run->brand_id !== $citation->brand_id) {
                throw new InvalidArgumentException('Visibility citation provider run must belong to the same tenant.');
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
            'rank' => 'integer',
            'trust_score' => 'integer',
            'metadata' => 'array',
        ];
    }
}
