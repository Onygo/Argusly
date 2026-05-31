<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
    'marketing_objective_id',
    'related_type',
    'related_id',
    'title',
    'description',
    'status',
    'priority',
    'assigned_to',
    'created_by',
    'due_at',
    'completed_at',
    'metadata',
])]
class MarketingTask extends Model
{
    use HasFactory;

    public const STATUSES = [
        'backlog',
        'todo',
        'in_progress',
        'waiting_review',
        'completed',
        'cancelled',
    ];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public const RELATED_MODELS = [
        ContentAsset::class,
        SocialPost::class,
        Recommendation::class,
        Approval::class,
        Campaign::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (MarketingTask $task): void {
            $task->uuid ??= (string) Str::uuid();
            $task->status ??= 'backlog';
            $task->priority ??= 'medium';
        });

        static::saving(function (MarketingTask $task): void {
            if (! in_array($task->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid marketing task status [{$task->status}].");
            }

            if (! in_array($task->priority, self::PRIORITIES, true)) {
                throw new InvalidArgumentException("Invalid marketing task priority [{$task->priority}].");
            }

            $task->validateBrand();
            $task->validateCampaign();
            $task->validateObjective();
            $task->validateRelated();
            $task->validateAssignee();
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

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MarketingObjective::class, 'marketing_objective_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
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
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
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
            throw new InvalidArgumentException('Marketing task brand must belong to the same account.');
        }
    }

    private function validateCampaign(): void
    {
        if ($this->campaign_id === null) {
            return;
        }

        $campaign = Campaign::query()->find($this->campaign_id);

        if (! $campaign || $campaign->account_id !== $this->account_id || $campaign->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Marketing task campaign must belong to the same account and brand scope.');
        }
    }

    private function validateObjective(): void
    {
        if ($this->marketing_objective_id === null) {
            return;
        }

        $objective = MarketingObjective::query()->find($this->marketing_objective_id);

        if (! $objective || $objective->account_id !== $this->account_id || $objective->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Marketing task objective must belong to the same account and brand scope.');
        }
    }

    private function validateRelated(): void
    {
        if ($this->related_type === null && $this->related_id === null) {
            return;
        }

        if ($this->related_type === null || $this->related_id === null || ! in_array($this->related_type, self::RELATED_MODELS, true)) {
            throw new InvalidArgumentException('Marketing task related record type is not supported.');
        }

        /** @var Model|null $related */
        $related = $this->related_type::query()->find($this->related_id);

        if (! $related || (int) $related->getAttribute('account_id') !== (int) $this->account_id) {
            throw new InvalidArgumentException('Marketing task related record must belong to the same account.');
        }

        $relatedBrandId = $related->getAttribute('brand_id');

        if ($relatedBrandId !== null && (int) $relatedBrandId !== (int) $this->brand_id) {
            throw new InvalidArgumentException('Marketing task related record must belong to the same brand scope.');
        }
    }

    private function validateAssignee(): void
    {
        if ($this->assigned_to === null) {
            return;
        }

        $user = User::query()->find($this->assigned_to);

        if (! $user || ! $user->memberships()->where('account_id', $this->account_id)->where('status', 'active')->exists()) {
            throw new InvalidArgumentException('Marketing task assignee must have access to the account.');
        }

        if ($this->brand_id !== null && ! $user->brandMemberships()
            ->where('account_id', $this->account_id)
            ->where('brand_id', $this->brand_id)
            ->where('status', 'active')
            ->exists()) {
            throw new InvalidArgumentException('Marketing task assignee must have access to the brand.');
        }
    }
}
