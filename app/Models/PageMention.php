<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PageMention extends Model
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
        'page_entity_id',
        'mention_type',
        'entity_type',
        'entity_key',
        'entity_name',
        'matched_text',
        'source_field',
        'position_start',
        'position_end',
        'evidence_snippet',
        'confidence_score',
        'observed_at',
        'analysis_method',
        'model_used',
        'dedupe_hash',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'position_start' => 'integer',
        'position_end' => 'integer',
        'confidence_score' => 'decimal:2',
        'observed_at' => 'datetime',
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

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PageEntity::class, 'page_entity_id');
    }
}
