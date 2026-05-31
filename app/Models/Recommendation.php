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
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'signal_id',
    'title',
    'summary',
    'recommended_action',
    'impact_score',
    'confidence_score',
    'status',
    'completed_at',
])]
class Recommendation extends Model
{
    use HasEvidence, HasFactory, HasTopics, RecordsDomainEvents;

    public const UPDATED_AT = null;

    public const STATUSES = ['new', 'accepted', 'dismissed', 'completed'];

    protected static function booted(): void
    {
        static::creating(function (Recommendation $recommendation): void {
            $recommendation->uuid ??= (string) Str::uuid();
            $recommendation->status ??= 'new';
        });

        static::saving(function (Recommendation $recommendation): void {
            if (! in_array($recommendation->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid recommendation status [{$recommendation->status}].");
            }

            if ($recommendation->brand_id !== null) {
                $brand = Brand::query()->find($recommendation->brand_id);

                if (! $brand || $brand->account_id !== $recommendation->account_id) {
                    throw new InvalidArgumentException('Recommendation brand must belong to the recommendation account.');
                }
            }

            if ($recommendation->signal_id !== null) {
                $signal = IntelligenceSignal::query()->find($recommendation->signal_id);

                if (! $signal || $signal->account_id !== $recommendation->account_id || $signal->brand_id !== $recommendation->brand_id) {
                    throw new InvalidArgumentException('Recommendation signal must belong to the same account and brand scope.');
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
     * @return BelongsTo<IntelligenceSignal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(IntelligenceSignal::class, 'signal_id');
    }

    /**
     * @param  Builder<Recommendation>  $query
     * @return Builder<Recommendation>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['new', 'accepted']);
    }

    public function accept(): bool
    {
        return $this->forceFill(['status' => 'accepted'])->save();
    }

    public function dismiss(): bool
    {
        return $this->forceFill(['status' => 'dismissed'])->save();
    }

    public function complete(): bool
    {
        return $this->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'impact_score' => 'integer',
            'confidence_score' => 'integer',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
