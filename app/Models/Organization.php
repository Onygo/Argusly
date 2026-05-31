<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

#[Fillable([
    'account_id',
    'name',
    'website',
    'industry',
    'description',
    'metadata',
])]
class Organization extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            $organization->uuid ??= (string) Str::uuid();
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
     * @return MorphMany<Relationship, $this>
     */
    public function outgoingRelationships(): MorphMany
    {
        return $this->morphMany(Relationship::class, 'from');
    }

    /**
     * @return MorphMany<Relationship, $this>
     */
    public function incomingRelationships(): MorphMany
    {
        return $this->morphMany(Relationship::class, 'to');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
