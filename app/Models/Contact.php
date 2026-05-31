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
    'first_name',
    'last_name',
    'display_name',
    'email',
    'phone',
    'website',
    'linkedin_url',
    'notes',
    'metadata',
])]
class Contact extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Contact $contact): void {
            $contact->uuid ??= (string) Str::uuid();
            $contact->display_name ??= trim("{$contact->first_name} {$contact->last_name}");
        });

        static::saving(function (Contact $contact): void {
            $contact->display_name = $contact->display_name ?: trim("{$contact->first_name} {$contact->last_name}");
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
