<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PageBrandMatch extends Model
{
    use BelongsToOrganizationViaWorkspace;
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
        'brand_ref_type',
        'brand_ref_id',
        'brand_key',
        'brand_name',
        'match_type',
        'match_score',
        'evidence_json',
        'observed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'match_score' => 'decimal:4',
        'evidence_json' => 'array',
        'observed_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(MonitoredPage::class, 'monitored_page_id');
    }
}
