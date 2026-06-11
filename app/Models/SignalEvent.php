<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignalEvent extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'signal_source_id',
        'signal_feed_item_id',
        'signal_mention_id',
        'signal_entity_id',
        'category',
        'type',
        'severity',
        'status',
        'topic',
        'entity_name',
        'entity_key',
        'signal_strength',
        'confidence_score',
        'impact_score',
        'urgency_score',
        'risk_score',
        'opportunity_score',
        'observed_at',
        'expires_at',
        'evidence',
        'metrics',
        'metadata',
        'dedupe_hash',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'category' => SignalCategory::class,
        'type' => SignalType::class,
        'severity' => SignalSeverity::class,
        'status' => SignalStatus::class,
        'signal_strength' => 'float',
        'confidence_score' => 'float',
        'impact_score' => 'float',
        'urgency_score' => 'float',
        'risk_score' => 'float',
        'opportunity_score' => 'float',
        'observed_at' => 'datetime',
        'expires_at' => 'datetime',
        'evidence' => 'array',
        'metrics' => 'array',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function signalSource(): BelongsTo
    {
        return $this->belongsTo(SignalSource::class);
    }

    public function signalFeedItem(): BelongsTo
    {
        return $this->belongsTo(SignalFeedItem::class);
    }

    public function signalMention(): BelongsTo
    {
        return $this->belongsTo(SignalMention::class);
    }

    public function signalEntity(): BelongsTo
    {
        return $this->belongsTo(SignalEntity::class);
    }

    public function detections(): BelongsToMany
    {
        return $this->belongsToMany(SignalDetection::class, 'signal_detection_links')
            ->using(SignalDetectionLink::class)
            ->withPivot(['id', 'weight', 'contribution'])
            ->withTimestamps();
    }

    public function scopeCategory(Builder $query, SignalCategory|string $category): Builder
    {
        return $query->where('category', $category instanceof SignalCategory ? $category->value : $category);
    }

    public function scopeStatus(Builder $query, SignalStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof SignalStatus ? $status->value : $status);
    }

    public function isOpen(): bool
    {
        return ! $this->status?->isTerminal();
    }
}
