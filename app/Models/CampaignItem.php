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
    'brand_id',
    'campaign_id',
    'campaign_stage_id',
    'related_type',
    'related_id',
    'title',
    'description',
    'status',
    'position',
    'assigned_to',
    'due_at',
    'metadata',
])]
class CampaignItem extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'blocked', 'completed', 'archived'];

    public const RELATED_MODELS = [
        ContentAsset::class,
        SocialPost::class,
        MarketingTask::class,
        Recommendation::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (CampaignItem $item): void {
            $item->uuid ??= (string) Str::uuid();
            $item->status ??= 'active';
        });

        static::saving(function (CampaignItem $item): void {
            if (! in_array($item->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid campaign item status [{$item->status}].");
            }

            $item->validateCampaign();
            $item->validateStage();
            $item->validateRelated();
            $item->validateAssignee();
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

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CampaignStage::class, 'campaign_stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    private function validateCampaign(): void
    {
        $campaign = Campaign::query()->find($this->campaign_id);

        if (! $campaign || $campaign->account_id !== $this->account_id || $campaign->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Campaign item must belong to the same campaign tenant.');
        }
    }

    private function validateStage(): void
    {
        if ($this->campaign_stage_id === null) {
            return;
        }

        $stage = CampaignStage::query()->find($this->campaign_stage_id);

        if (! $stage || $stage->account_id !== $this->account_id || $stage->brand_id !== $this->brand_id || $stage->campaign_id !== $this->campaign_id) {
            throw new InvalidArgumentException('Campaign item stage must belong to the same campaign.');
        }
    }

    private function validateRelated(): void
    {
        if ($this->related_type === null && $this->related_id === null) {
            return;
        }

        if ($this->related_type === null || $this->related_id === null || ! in_array($this->related_type, self::RELATED_MODELS, true)) {
            throw new InvalidArgumentException('Campaign item related record type is not supported.');
        }

        /** @var Model|null $related */
        $related = $this->related_type::query()->find($this->related_id);

        if (! $related || (int) $related->getAttribute('account_id') !== (int) $this->account_id) {
            throw new InvalidArgumentException('Campaign item related record must belong to the same account.');
        }

        $relatedBrandId = $related->getAttribute('brand_id');

        if ($relatedBrandId !== null && (int) $relatedBrandId !== (int) $this->brand_id) {
            throw new InvalidArgumentException('Campaign item related record must belong to the same brand.');
        }
    }

    private function validateAssignee(): void
    {
        if ($this->assigned_to === null) {
            return;
        }

        $user = User::query()->find($this->assigned_to);

        if (! $user || ! $user->brandMemberships()
            ->where('account_id', $this->account_id)
            ->where('brand_id', $this->brand_id)
            ->where('status', 'active')
            ->exists()) {
            throw new InvalidArgumentException('Campaign item assignee must have access to the campaign brand.');
        }
    }
}
