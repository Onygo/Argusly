<?php

namespace App\Models;

use App\Services\ContentLanguageService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'campaign_id',
    'email_provider_id',
    'title',
    'subject',
    'preheader',
    'language',
    'status',
    'scheduled_at',
    'sent_at',
    'created_by',
    'approved_by',
    'approved_at',
    'metadata',
])]
class Newsletter extends Model
{
    use HasFactory;

    public const STATUSES = [
        'draft',
        'review',
        'approved',
        'scheduled',
        'sending',
        'sent',
        'failed',
        'archived',
    ];

    protected static function booted(): void
    {
        static::creating(function (Newsletter $newsletter): void {
            $newsletter->uuid ??= (string) Str::uuid();
            $newsletter->status ??= 'draft';
            $newsletter->language ??= app(ContentLanguageService::class)->defaultFor($newsletter->brand, $newsletter->account);
        });

        static::saving(function (Newsletter $newsletter): void {
            if (! in_array($newsletter->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid newsletter status [{$newsletter->status}].");
            }

            $newsletter->validateBrand();
            $newsletter->validateCampaign();
            $newsletter->validateEmailProvider();
            app(ContentLanguageService::class)->validateForBrand($newsletter->language, $newsletter->brand);
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

    public function emailProvider(): BelongsTo
    {
        return $this->belongsTo(EmailProvider::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(NewsletterSection::class)->orderBy('position')->orderBy('id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(NewsletterSend::class);
    }

    public function scopeForTenant(Builder $query, Account $account, ?Brand $brand = null): Builder
    {
        return $query->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $scope) => $scope->where('brand_id', $brand->id));
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    private function validateBrand(): void
    {
        $brand = Brand::query()->find($this->brand_id);

        if (! $brand || $brand->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Newsletter brand must belong to the same account.');
        }
    }

    private function validateCampaign(): void
    {
        if ($this->campaign_id === null) {
            return;
        }

        $campaign = Campaign::query()->find($this->campaign_id);

        if (! $campaign || $campaign->account_id !== $this->account_id || $campaign->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Newsletter campaign must belong to the same account and brand scope.');
        }
    }

    private function validateEmailProvider(): void
    {
        if ($this->email_provider_id === null) {
            return;
        }

        $provider = EmailProvider::query()->find($this->email_provider_id);

        if (! $provider || $provider->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Newsletter email provider must belong to the same account.');
        }

        if ($provider->brand_id !== null && $provider->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Newsletter email provider must belong to the same brand scope.');
        }
    }
}
