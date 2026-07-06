<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PageScore extends Model
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
        'monitored_page_id',
        'page_snapshot_id',
        'page_content_extraction_id',
        'score_type',
        'score',
        'previous_score',
        'delta',
        'score_version',
        'calculation_method',
        'model_used',
        'explanation',
        'breakdown_json',
        'evidence_json',
        'computed_at',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'score' => 'decimal:2',
        'previous_score' => 'decimal:2',
        'delta' => 'decimal:2',
        'breakdown_json' => 'array',
        'evidence_json' => 'array',
        'computed_at' => 'datetime',
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
