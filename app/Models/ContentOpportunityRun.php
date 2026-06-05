<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentOpportunityRun extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'status',
        'source_type',
        'input',
        'result',
        'candidates_count',
        'created_count',
        'refreshed_count',
        'failure_reason',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'input' => 'array',
        'result' => 'array',
        'candidates_count' => 'integer',
        'created_count' => 'integer',
        'refreshed_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(ContentOpportunity::class);
    }
}
