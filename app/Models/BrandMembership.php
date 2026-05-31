<?php

namespace App\Models;

use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'brand_id', 'account_id', 'status', 'joined_at'])]
class BrandMembership extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (BrandMembership $membership): void {
            app(ActivityLogger::class)->log(
                event: 'membership.changed',
                description: 'A user was assigned to a brand.',
                account: $membership->account,
                brand: $membership->brand,
                user: $membership->user,
                subject: $membership,
                properties: [
                    'membership_id' => $membership->id,
                    'status' => $membership->status,
                ],
            );
        });

        static::updated(function (BrandMembership $membership): void {
            app(ActivityLogger::class)->log(
                event: 'membership.changed',
                description: 'A brand membership was changed.',
                account: $membership->account,
                brand: $membership->brand,
                user: $membership->user,
                subject: $membership,
                properties: [
                    'membership_id' => $membership->id,
                    'changes' => $membership->getChanges(),
                ],
            );
        });

        static::deleted(function (BrandMembership $membership): void {
            app(ActivityLogger::class)->log(
                event: 'membership.changed',
                description: 'A brand membership was removed.',
                account: $membership->account,
                brand: $membership->brand,
                user: $membership->user,
                subject: $membership,
            );
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }
}
