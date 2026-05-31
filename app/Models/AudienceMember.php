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
    'audience_id',
    'contact_id',
    'email',
    'first_name',
    'last_name',
    'status',
    'source',
    'metadata',
])]
class AudienceMember extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'inactive', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (AudienceMember $member): void {
            $member->uuid ??= (string) Str::uuid();
            $member->status ??= 'active';
            $member->source ??= 'manual';
        });

        static::saving(function (AudienceMember $member): void {
            if (! in_array($member->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid audience member status [{$member->status}].");
            }

            $member->email = Str::lower($member->email);
            $member->validateAudience();
            $member->validateContact();
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    private function validateAudience(): void
    {
        $audience = Audience::query()->find($this->audience_id);

        if (! $audience || $audience->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Audience member audience must belong to the same account.');
        }
    }

    private function validateContact(): void
    {
        if ($this->contact_id === null) {
            return;
        }

        $contact = Contact::query()->find($this->contact_id);

        if (! $contact || $contact->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Audience member contact must belong to the same account.');
        }
    }
}
