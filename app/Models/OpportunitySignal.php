<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpportunitySignal extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'content_id',
        'content_cluster_id',
        'campaign_id',
        'source',
        'category',
        'topic',
        'entity',
        'signal_strength',
        'confidence',
        'observed_at',
        'metrics',
        'evidence',
        'metadata',
        'dedupe_hash',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'source' => OpportunitySignalSource::class,
        'category' => OpportunityCategory::class,
        'signal_strength' => 'float',
        'confidence' => 'float',
        'observed_at' => 'datetime',
        'metrics' => 'array',
        'evidence' => 'array',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function contentCluster(): BelongsTo
    {
        return $this->belongsTo(ContentCluster::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function opportunities(): BelongsToMany
    {
        return $this->belongsToMany(Opportunity::class, 'opportunity_signal_links')
            ->withPivot(['weight', 'contribution'])
            ->withTimestamps();
    }
}
