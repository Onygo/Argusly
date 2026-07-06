<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PageAlert extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_FIRED = 'fired';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'alert_rule_id',
        'monitored_page_id',
        'page_snapshot_id',
        'signal_event_id',
        'signal_detection_id',
        'notification_id',
        'recommended_action_id',
        'trigger',
        'severity',
        'status',
        'title',
        'summary',
        'alert_key',
        'dedupe_hash',
        'evidence_json',
        'metrics_json',
        'metadata_json',
        'fired_at',
        'dismissed_at',
        'resolved_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'evidence_json' => 'array',
        'metrics_json' => 'array',
        'metadata_json' => 'array',
        'fired_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(MonitoredPage::class, 'monitored_page_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'page_snapshot_id');
    }

    public function signalEvent(): BelongsTo
    {
        return $this->belongsTo(SignalEvent::class, 'signal_event_id');
    }

    public function signalDetection(): BelongsTo
    {
        return $this->belongsTo(SignalDetection::class, 'signal_detection_id');
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function recommendedAction(): BelongsTo
    {
        return $this->belongsTo(RecommendedAction::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_DISMISSED, self::STATUS_RESOLVED]);
    }

    public function markDismissed(): self
    {
        $this->forceFill([
            'status' => self::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ])->save();

        return $this->refresh();
    }

    public function markResolved(): self
    {
        $this->forceFill([
            'status' => self::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        return $this->refresh();
    }
}
