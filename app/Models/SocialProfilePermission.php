<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'social_profile_id',
    'user_id',
    'account_id',
    'brand_id',
    'can_view',
    'can_prepare',
    'can_schedule',
    'can_publish',
    'can_manage',
])]
class SocialProfilePermission extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (SocialProfilePermission $permission): void {
            if ($permission->brand_id === null) {
                return;
            }

            $brand = Brand::query()->find($permission->brand_id);

            if (! $brand || ($permission->account_id !== null && $brand->account_id !== $permission->account_id)) {
                throw new InvalidArgumentException('Social profile permission brand must belong to the same account.');
            }
        });
    }

    /**
     * @return BelongsTo<SocialProfile, $this>
     */
    public function socialProfile(): BelongsTo
    {
        return $this->belongsTo(SocialProfile::class);
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

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_prepare' => 'boolean',
            'can_schedule' => 'boolean',
            'can_publish' => 'boolean',
            'can_manage' => 'boolean',
        ];
    }
}
