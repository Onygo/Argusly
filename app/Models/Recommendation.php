<?php

namespace App\Models;

use App\Models\Concerns\HasEvidence;
use App\Models\Concerns\HasTopics;
use App\Models\Concerns\RecordsDomainEvents;
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
    'signal_id',
    'title',
    'summary',
    'recommended_action',
    'action_type',
    'action_payload',
    'impact_score',
    'confidence_score',
    'status',
    'accepted_by',
    'accepted_at',
    'executed_at',
    'execution_status',
    'completed_at',
])]
class Recommendation extends Model
{
    use HasEvidence, HasFactory, HasTopics, RecordsDomainEvents;

    public const UPDATED_AT = null;

    public const STATUSES = ['new', 'accepted', 'dismissed', 'completed'];

    public const ACTION_TYPES = [
        'run_content_audit',
        'refresh_content',
        'create_answer_block',
        'translate_content',
        'create_social_post',
        'schedule_social_post',
        'run_visibility_check',
        'reconnect_integration',
        'create_campaign_task_plan',
        'create_campaign_briefing',
        'create_newsletter_digest',
        'create_audience_newsletter',
        'submit_newsletter_for_approval',
        'schedule_newsletter',
        'attach_content_to_campaign',
        'attach_social_post_to_campaign',
        'create_objective_actions',
        'create_content',
        'refresh_positioning',
        'launch_campaign',
        'improve_citations',
        'review_credit_usage',
    ];

    public const EXECUTION_STATUSES = ['pending', 'queued', 'completed', 'failed'];

    protected static function booted(): void
    {
        static::creating(function (Recommendation $recommendation): void {
            $recommendation->uuid ??= (string) Str::uuid();
            $recommendation->status ??= 'new';
        });

        static::saving(function (Recommendation $recommendation): void {
            if (! in_array($recommendation->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid recommendation status [{$recommendation->status}].");
            }

            if ($recommendation->action_type !== null && ! in_array($recommendation->action_type, self::ACTION_TYPES, true)) {
                throw new InvalidArgumentException("Invalid recommendation action type [{$recommendation->action_type}].");
            }

            if ($recommendation->execution_status !== null && ! in_array($recommendation->execution_status, self::EXECUTION_STATUSES, true)) {
                throw new InvalidArgumentException("Invalid recommendation execution status [{$recommendation->execution_status}].");
            }

            if ($recommendation->brand_id !== null) {
                $brand = Brand::query()->find($recommendation->brand_id);

                if (! $brand || $brand->account_id !== $recommendation->account_id) {
                    throw new InvalidArgumentException('Recommendation brand must belong to the recommendation account.');
                }
            }

            if ($recommendation->signal_id !== null) {
                $signal = IntelligenceSignal::query()->find($recommendation->signal_id);

                if (! $signal || $signal->account_id !== $recommendation->account_id || $signal->brand_id !== $recommendation->brand_id) {
                    throw new InvalidArgumentException('Recommendation signal must belong to the same account and brand scope.');
                }
            }
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
     * @return BelongsTo<IntelligenceSignal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(IntelligenceSignal::class, 'signal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * @param  Builder<Recommendation>  $query
     * @return Builder<Recommendation>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['new', 'accepted']);
    }

    public function accept(?User $user = null): bool
    {
        return $this->forceFill([
            'status' => 'accepted',
            'accepted_by' => $user?->id,
            'accepted_at' => now(),
            'execution_status' => $this->action_type ? ($this->execution_status ?? 'pending') : $this->execution_status,
        ])->save();
    }

    public function dismiss(): bool
    {
        return $this->forceFill(['status' => 'dismissed'])->save();
    }

    public function complete(): bool
    {
        return $this->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'impact_score' => 'integer',
            'confidence_score' => 'integer',
            'action_payload' => 'array',
            'accepted_at' => 'datetime',
            'executed_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
