<?php

namespace App\Models;

use App\Services\ContentLanguageService;
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
    'campaign_id',
    'title',
    'objective',
    'audience',
    'tone_of_voice',
    'key_message',
    'channels',
    'languages',
    'status',
    'created_by',
    'approved_by',
    'approved_at',
    'metadata',
])]
class Briefing extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'review', 'approved', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (Briefing $briefing): void {
            $briefing->uuid ??= (string) Str::uuid();
            $briefing->status ??= 'draft';
        });

        static::saving(function (Briefing $briefing): void {
            if (! in_array($briefing->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid briefing status [{$briefing->status}].");
            }

            $briefing->validateBrand();
            $briefing->validateCampaign();
            $briefing->validateLanguages();
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeForTenant(Builder $query, Account $account, ?Brand $brand = null): Builder
    {
        return $query->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $scope) => $scope->where(fn (Builder $brandScope) => $brandScope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $scope) => $scope->whereNull('brand_id'),
            );
    }

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'languages' => 'array',
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    private function validateBrand(): void
    {
        if ($this->brand_id === null) {
            return;
        }

        $brand = Brand::query()->find($this->brand_id);

        if (! $brand || $brand->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Briefing brand must belong to the same account.');
        }
    }

    private function validateCampaign(): void
    {
        if ($this->campaign_id === null) {
            return;
        }

        $campaign = Campaign::query()->find($this->campaign_id);

        if (! $campaign || $campaign->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Briefing campaign must belong to the same account.');
        }

        if ($this->brand_id !== null && $campaign->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Briefing campaign must belong to the same brand scope.');
        }
    }

    private function validateLanguages(): void
    {
        if ($this->languages === null || $this->languages === []) {
            return;
        }

        $brand = $this->brand_id ? Brand::query()->find($this->brand_id) : null;
        $languages = app(ContentLanguageService::class);

        foreach ($this->languages as $language) {
            if (! is_string($language)) {
                throw new InvalidArgumentException('Briefing languages must be language codes.');
            }

            $languages->validateForBrand($language, $brand);
        }
    }
}
