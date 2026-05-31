<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'competitor_id',
    'captured_at',
    'visibility_score',
    'mention_score',
    'share_of_voice',
    'metadata',
])]
class CompetitorSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (CompetitorSnapshot $snapshot): void {
            $snapshot->uuid ??= (string) Str::uuid();
            $snapshot->captured_at ??= now();
        });
    }

    /**
     * @return BelongsTo<Competitor, $this>
     */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'visibility_score' => 'integer',
            'mention_score' => 'integer',
            'share_of_voice' => 'integer',
            'metadata' => 'array',
        ];
    }
}
