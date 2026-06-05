<?php

namespace App\Models;

use App\Models\Concerns\HasEvidence;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'account_id',
    'brand_id',
    'source',
    'type',
    'category',
    'priority',
    'severity',
    'dedupe_key',
    'title',
    'summary',
    'impact_score',
    'confidence_score',
    'status',
    'recommended_action',
    'payload',
    'detected_at',
    'reviewed_at',
    'dismissed_at',
    'resolved_at',
])]
class IntelligenceSignal extends Model
{
    use HasEvidence, HasFactory;

    public const STATUSES = ['new', 'reviewed', 'in_progress', 'resolved', 'dismissed'];

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    public const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];

    public const CATEGORIES = ['content', 'visibility', 'competitor', 'social', 'system', 'billing', 'integration', 'narrative'];

    public const TYPES = [
        'visibility_change',
        'content_opportunity',
        'competitor_movement',
        'social_opportunity',
        'technical_issue',
        'agent_recommendation',
        'integration_event',
        'content_event',
        'content_audit_completed',
        'lifecycle_score_degraded',
        'generation_completed',
        'credits_low',
        'integration_connected',
        'publishing_failed',
        'publishing_completed',
        'narrative_gap_detected',
        'mention_captured',
        'sentiment_shift',
        'topic_velocity',
        'competitor_mention',
    ];

    protected static function booted(): void
    {
        static::creating(function (IntelligenceSignal $signal): void {
            $signal->uuid ??= (string) Str::uuid();
            $signal->detected_at ??= now();
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
     * @return HasMany<Recommendation, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class, 'signal_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(SignalAlert::class, 'intelligence_signal_id');
    }

    /**
     * @return BelongsToMany<Campaign, $this>
     */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_signals')
            ->withTimestamps();
    }

    /**
     * @param  Builder<IntelligenceSignal>  $query
     * @return Builder<IntelligenceSignal>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['resolved', 'dismissed']);
    }

    public function markReviewed(): bool
    {
        return $this->forceFill([
            'status' => 'reviewed',
            'reviewed_at' => now(),
        ])->save();
    }

    public function dismiss(): bool
    {
        return $this->forceFill([
            'status' => 'dismissed',
            'dismissed_at' => now(),
            'resolved_at' => $this->resolved_at ?? now(),
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
            'payload' => 'array',
            'detected_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
