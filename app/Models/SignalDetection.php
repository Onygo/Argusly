<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignalDetection extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const CATEGORY_BRAND_MONITORING = 'brand_monitoring';
    public const CATEGORY_COMPETITOR_MONITORING = 'competitor_monitoring';
    public const CATEGORY_TREND_DETECTION = 'trend_detection';
    public const CATEGORY_OPPORTUNITY_DETECTION = 'opportunity_detection';
    public const CATEGORY_RISK_DETECTION = 'risk_detection';
    public const CATEGORY_FEED_PROCESSING = 'feed_processing';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'category',
        'type',
        'status',
        'title',
        'summary',
        'primary_topic',
        'primary_entity',
        'severity',
        'priority_score',
        'confidence_score',
        'impact_score',
        'urgency_score',
        'risk_score',
        'opportunity_score',
        'score_breakdown',
        'evidence_summary',
        'recommended_actions',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'dedupe_hash',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'status' => SignalStatus::class,
        'severity' => SignalSeverity::class,
        'priority_score' => 'float',
        'confidence_score' => 'float',
        'impact_score' => 'float',
        'urgency_score' => 'float',
        'risk_score' => 'float',
        'opportunity_score' => 'float',
        'score_breakdown' => 'array',
        'evidence_summary' => 'array',
        'recommended_actions' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    public static function categories(): array
    {
        return [
            self::CATEGORY_BRAND_MONITORING,
            self::CATEGORY_COMPETITOR_MONITORING,
            self::CATEGORY_TREND_DETECTION,
            self::CATEGORY_OPPORTUNITY_DETECTION,
            self::CATEGORY_RISK_DETECTION,
            self::CATEGORY_FEED_PROCESSING,
        ];
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(SignalEvent::class, 'signal_detection_links')
            ->using(SignalDetectionLink::class)
            ->withPivot(['id', 'weight', 'contribution'])
            ->withTimestamps();
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            SignalStatus::PUBLISHED->value,
            SignalStatus::DISMISSED->value,
            SignalStatus::RESOLVED->value,
            SignalStatus::ARCHIVED->value,
        ]);
    }

    public function isResolved(): bool
    {
        return $this->status === SignalStatus::RESOLVED || $this->resolved_at !== null;
    }

    public function markReviewing(): self
    {
        return $this->transitionTo(SignalStatus::REVIEWING);
    }

    public function markPublished(): self
    {
        return $this->transitionTo(SignalStatus::PUBLISHED);
    }

    public function markDismissed(): self
    {
        return $this->transitionTo(SignalStatus::DISMISSED);
    }

    public function markResolved(): self
    {
        return $this->transitionTo(SignalStatus::RESOLVED, ['resolved_at' => now()]);
    }

    public function archive(): self
    {
        return $this->transitionTo(SignalStatus::ARCHIVED);
    }

    public function canTransitionTo(SignalStatus|string $target): bool
    {
        $target = $target instanceof SignalStatus ? $target : SignalStatus::from($target);
        $current = $this->currentStatus();

        return $target !== $current && in_array($target, $this->allowedTransitionsFrom($current), true);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function transitionTo(SignalStatus $target, array $attributes = []): self
    {
        $current = $this->currentStatus();

        if (! $this->canTransitionTo($target)) {
            throw new \InvalidArgumentException("Cannot transition detection from {$current->value} to {$target->value}.");
        }

        $this->forceFill(array_merge(['status' => $target->value], $attributes))->save();

        return $this->refresh();
    }

    private function currentStatus(): SignalStatus
    {
        return $this->status instanceof SignalStatus
            ? $this->status
            : SignalStatus::from((string) $this->status);
    }

    /**
     * @return array<int, SignalStatus>
     */
    private function allowedTransitionsFrom(SignalStatus $current): array
    {
        return match ($current) {
            SignalStatus::NEW, SignalStatus::DETECTED => [
                SignalStatus::REVIEWING,
                SignalStatus::PUBLISHED,
                SignalStatus::DISMISSED,
                SignalStatus::RESOLVED,
                SignalStatus::ARCHIVED,
            ],
            SignalStatus::PROCESSING => [
                SignalStatus::DETECTED,
                SignalStatus::REVIEWING,
                SignalStatus::DISMISSED,
                SignalStatus::ARCHIVED,
            ],
            SignalStatus::REVIEWING => [
                SignalStatus::PUBLISHED,
                SignalStatus::DISMISSED,
                SignalStatus::RESOLVED,
                SignalStatus::ARCHIVED,
            ],
            SignalStatus::PUBLISHED => [
                SignalStatus::RESOLVED,
                SignalStatus::ARCHIVED,
            ],
            SignalStatus::RESOLVED => [
                SignalStatus::ARCHIVED,
            ],
            SignalStatus::DISMISSED, SignalStatus::ARCHIVED => [],
        };
    }
}
