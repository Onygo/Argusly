<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PageSentiment extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const TARGET_PAGE = 'page';
    public const TARGET_ENTITY = 'entity';
    public const TARGET_BRAND = 'brand';
    public const TARGET_COMPETITOR = 'competitor';
    public const TARGET_TOPIC = 'topic';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'monitored_page_id',
        'page_snapshot_id',
        'page_content_extraction_id',
        'target_type',
        'target_key',
        'target_name',
        'target_ref_type',
        'target_ref_id',
        'compound_score',
        'label',
        'confidence_score',
        'analysis_method',
        'model_used',
        'analyzer_version',
        'explanation',
        'evidence_json',
        'analyzed_at',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'compound_score' => 'decimal:4',
        'confidence_score' => 'decimal:2',
        'evidence_json' => 'array',
        'analyzed_at' => 'datetime',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(MonitoredPage::class, 'monitored_page_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'page_snapshot_id');
    }

    public function extraction(): BelongsTo
    {
        return $this->belongsTo(PageContentExtraction::class, 'page_content_extraction_id');
    }
}
