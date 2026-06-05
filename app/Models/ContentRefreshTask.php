<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\ContentRefreshTaskStatus;
use App\Enums\ContentRefreshTaskType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentRefreshTask extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'content_id',
        'workspace_id',
        'client_site_id',
        'content_lifecycle_analysis_id',
        'campaign_id',
        'type',
        'status',
        'priority',
        'title',
        'description',
        'recommended_actions',
        'evidence',
        'due_at',
        'completed_at',
    ];

    protected $casts = [
        'type' => ContentRefreshTaskType::class,
        'status' => ContentRefreshTaskStatus::class,
        'priority' => 'integer',
        'recommended_actions' => 'array',
        'evidence' => 'array',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ContentRefreshTaskStatus::OPEN->value,
            ContentRefreshTaskStatus::QUEUED->value,
            ContentRefreshTaskStatus::IN_PROGRESS->value,
        ]);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(ContentLifecycleAnalysis::class, 'content_lifecycle_analysis_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
