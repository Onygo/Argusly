<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnrichmentRun extends Model
{
    use HasFactory;
    use HasUuids;

    public const ENRICHABLE_ORGANIZATION = 'organization';
    public const ENRICHABLE_PERSONA = 'persona';
    public const ENRICHABLE_TEAM_MEMBER = 'team_member';

    public const TYPE_ORGANIZATION = 'organization_enrichment';
    public const TYPE_BUYER_PERSONA = 'buyer_persona_enrichment';
    public const TYPE_TEAM_MEMBER_PERSONA = 'team_member_persona_enrichment';
    public const TYPE_BRAND_CONTEXT = 'brand_context_enrichment';

    public const GENERATION_MODE_FULL = 'full';
    public const GENERATION_MODE_MISSING_ONLY = 'missing_only';
    public const GENERATION_MODE_REGENERATE = 'regenerate';

    public const BRAND_SECTIONS = [
        'company_profile',
        'brand_voices',
        'buyer_personas',
        'team_personas',
    ];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_EMPTY = 'completed_empty';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'enrichable_type',
        'enrichable_id',
        'enrichment_type',
        'source_type',
        'source_payload',
        'extracted_payload',
        'ai_payload',
        'requested_sections',
        'generation_mode',
        'status',
        'progress',
        'error_message',
        'failure_reason',
        'diagnostic_payload',
        'approved_by',
        'approved_at',
        'queued_at',
        'started_at',
        'completed_at',
        'failed_at',
        'last_heartbeat_at',
    ];

    protected $casts = [
        'source_payload' => 'array',
        'extracted_payload' => 'array',
        'ai_payload' => 'array',
        'requested_sections' => 'array',
        'progress' => 'float',
        'diagnostic_payload' => 'array',
        'approved_at' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_RUNNING], true);
    }

    public function isCompletedSuccessfully(): bool
    {
        return (string) $this->status === self::STATUS_COMPLETED;
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_COMPLETED_EMPTY,
            self::STATUS_FAILED,
            self::STATUS_APPROVED,
            self::STATUS_REVIEWED,
            self::STATUS_REJECTED,
        ], true);
    }
}
