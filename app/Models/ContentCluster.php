<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentCluster extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'name',
        'topic_keyword',
        'pillar_content_id',
        'supporting_content_ids',
        'cluster_score',
        'meta',
    ];

    protected $casts = [
        'supporting_content_ids' => 'array',
        'cluster_score' => 'float',
        'meta' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function pillarContent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'pillar_content_id');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
