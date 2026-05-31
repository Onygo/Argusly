<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'from_type',
    'from_id',
    'to_type',
    'to_id',
    'relationship_type',
    'strength',
    'metadata',
])]
class Relationship extends Model
{
    use HasFactory;

    public const TYPES = [
        'employee',
        'partner',
        'customer',
        'creator',
        'journalist',
        'media',
        'expert',
        'analyst',
        'influencer',
        'competitor',
    ];

    protected static function booted(): void
    {
        static::creating(function (Relationship $relationship): void {
            $relationship->uuid ??= (string) Str::uuid();
        });

        static::saving(function (Relationship $relationship): void {
            if (! in_array($relationship->relationship_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid relationship type [{$relationship->relationship_type}].");
            }

            if ($relationship->from_type === $relationship->to_type && $relationship->from_id === $relationship->to_id) {
                throw new InvalidArgumentException('Relationship endpoints must be different.');
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

    public function from(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'from_type', 'from_id');
    }

    public function to(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'to_type', 'to_id');
    }

    protected function casts(): array
    {
        return [
            'strength' => 'integer',
            'metadata' => 'array',
        ];
    }
}
