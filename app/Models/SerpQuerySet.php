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

class SerpQuerySet extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'name',
        'description',
        'locale',
        'country',
        'device',
        'search_engine',
        'provider_key',
        'cadence',
        'status',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function queries(): HasMany
    {
        return $this->hasMany(SerpQuery::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(PageSerpObservation::class);
    }
}
