<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteScan extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_CRAWLING = 'crawling';
    public const STATUS_EXTRACTING = 'extracting';
    public const STATUS_ANALYZING = 'analyzing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'user_id',
        'url',
        'status',
        'progress',
        'crawled_pages',
        'extracted_content',
        'brand_profile',
        'seo_profile',
        'design_profile',
        'technical_profile',
        'suggested_briefs',
        'user_confirmed',
        'started_at',
        'completed_at',
        'failed_at',
        'error_code',
        'error_message',
        'meta',
    ];

    protected $casts = [
        'progress' => 'float',
        'crawled_pages' => 'array',
        'extracted_content' => 'array',
        'brand_profile' => 'array',
        'seo_profile' => 'array',
        'design_profile' => 'array',
        'technical_profile' => 'array',
        'suggested_briefs' => 'array',
        'user_confirmed' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_CRAWLING,
            self::STATUS_EXTRACTING,
            self::STATUS_ANALYZING,
        ], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
