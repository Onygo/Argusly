<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['account_id', 'brand_id', 'catalog_code', 'credits_used', 'executions', 'period_start', 'period_end'])]
class CreditUsageStat extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (CreditUsageStat $stat): void {
            $stat->uuid ??= (string) Str::uuid();
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits_used' => 'integer',
            'executions' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }
}
