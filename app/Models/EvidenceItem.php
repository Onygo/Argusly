<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'source_id',
    'subject_type',
    'subject_id',
    'evidence_type',
    'title',
    'url',
    'snippet',
    'raw_payload',
    'confidence_score',
    'captured_at',
])]
class EvidenceItem extends Model
{
    use HasFactory;

    public const TYPES = [
        'mention',
        'citation',
        'search_result',
        'ai_answer',
        'social_post',
        'web_page',
        'manual_note',
        'provider_payload',
    ];

    protected static function booted(): void
    {
        static::creating(function (EvidenceItem $evidence): void {
            $evidence->uuid ??= (string) Str::uuid();
            $evidence->captured_at ??= now();
        });

        static::saving(function (EvidenceItem $evidence): void {
            if (! in_array($evidence->evidence_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid evidence type [{$evidence->evidence_type}].");
            }

            if ($evidence->confidence_score !== null && ($evidence->confidence_score < 0 || $evidence->confidence_score > 100)) {
                throw new InvalidArgumentException('Evidence confidence score must be between 0 and 100.');
            }

            if ($evidence->brand_id !== null) {
                $brand = Brand::query()->find($evidence->brand_id);

                if (! $brand || $brand->account_id !== $evidence->account_id) {
                    throw new InvalidArgumentException('Evidence brand must belong to the same account.');
                }
            }

            if ($evidence->source_id !== null) {
                $source = Source::query()->find($evidence->source_id);

                if (! $source || ($source->account_id !== null && $source->account_id !== $evidence->account_id)) {
                    throw new InvalidArgumentException('Evidence source must belong to the same account.');
                }

                if ($source->brand_id !== null && $source->brand_id !== $evidence->brand_id) {
                    throw new InvalidArgumentException('Evidence source must belong to the same brand scope.');
                }
            }

            $subject = $evidence->resolveSubject();

            if (! $subject) {
                throw new InvalidArgumentException('Evidence subject must exist.');
            }

            if ((int) $subject->getAttribute('account_id') !== (int) $evidence->account_id) {
                throw new InvalidArgumentException('Evidence subject must belong to the same account.');
            }

            $subjectBrandId = $subject->getAttribute('brand_id');

            if ($subjectBrandId !== null && (int) $subjectBrandId !== (int) $evidence->brand_id) {
                throw new InvalidArgumentException('Evidence subject must belong to the same brand scope.');
            }

            if ($subjectBrandId === null && $evidence->brand_id !== null) {
                throw new InvalidArgumentException('Account-level evidence subjects cannot receive brand-scoped evidence.');
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
        return $this->belongsTo(Source::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    private function resolveSubject(): ?Model
    {
        $class = Relation::getMorphedModel($this->subject_type) ?? $this->subject_type;

        if (! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($this->subject_id);
    }

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'confidence_score' => 'integer',
            'captured_at' => 'datetime',
        ];
    }
}
