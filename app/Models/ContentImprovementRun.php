<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentImprovementRun extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NO_CHANGES = 'no_changes';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'content_id',
        'organization_id',
        'type',
        'recommendation_label',
        'status',
        'progress_percentage',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
        'created_by',
        'draft_id',
        'recommendation_run_id',
        'recommendation_key',
        'source_content_id',
        'source_draft_id',
        'source_content_version_id',
        'source_content_revision_id',
        'source_revision_hash',
        'target_draft_id',
        'target_content_version_id',
        'output_revision_hash',
        'before_score',
        'after_score',
        'generated_summary',
        'diff_summary',
        'result_payload',
        'diagnostics',
        'applied_at',
        'applied_by',
    ];

    protected $casts = [
        'progress_percentage' => 'integer',
        'before_score' => 'integer',
        'after_score' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'result_payload' => 'array',
        'diagnostics' => 'array',
        'applied_at' => 'datetime',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function sourceDraft(): BelongsTo
    {
        return $this->belongsTo(Draft::class, 'source_draft_id');
    }

    public function targetDraft(): BelongsTo
    {
        return $this->belongsTo(Draft::class, 'target_draft_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContentImprovementEvent::class)->latest('id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function hasResult(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_NO_CHANGES], true)
            && is_array($this->result_payload);
    }
}
