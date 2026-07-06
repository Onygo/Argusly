<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PageIntelligenceReport extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_GENERATED = 'generated';

    public const ARTIFACT_TYPE_PDF = 'pdf';
    public const ARTIFACT_STATUS_PENDING = 'pending';
    public const ARTIFACT_STATUS_GENERATING = 'generating';
    public const ARTIFACT_STATUS_READY = 'ready';
    public const ARTIFACT_STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'market_pack_id',
        'market_pack_key',
        'report_type',
        'identity_hash',
        'idempotency_key',
        'title',
        'status',
        'snapshot_version',
        'template_version',
        'period_start',
        'period_end',
        'summary',
        'payload_json',
        'provenance_json',
        'generated_by',
        'generated_at',
        'artifact_type',
        'artifact_storage_path',
        'artifact_status',
        'artifact_generated_at',
        'artifact_checksum',
        'artifact_source_checksum',
        'artifact_failed_at',
        'artifact_error',
        'artifact_attempt_count',
        'scheduled_page_intelligence_briefing_id',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'snapshot_version' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'payload_json' => 'array',
        'provenance_json' => 'array',
        'generated_at' => 'datetime',
        'artifact_generated_at' => 'datetime',
        'artifact_failed_at' => 'datetime',
        'artifact_attempt_count' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function marketPack(): BelongsTo
    {
        return $this->belongsTo(MarketPack::class, 'market_pack_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function scheduledBriefing(): BelongsTo
    {
        return $this->belongsTo(ScheduledPageIntelligenceBriefing::class, 'scheduled_page_intelligence_briefing_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PageIntelligenceReportDelivery::class, 'report_id')
            ->latest('created_at');
    }
}
